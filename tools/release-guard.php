<?php

/*
 * Contao Migrator
 *
 * Package: vtinnovations/migrator
 * Copyright: V&T Innovations Team
 * Licence: LGPL-3.0-or-later
 * Website: https://www.v-t.one
 */

declare(strict_types=1);

/*
 * Build guard. Run before packaging a distributable artefact:
 *
 *     php tools/release-guard.php [path-to-artefact-root]
 *
 * It refuses a release that cannot verify a real V-T.ONE response, that ships development-only
 * licence material, or whose entitlement gate has been short-circuited. Exit code 0 = releasable.
 *
 * This is a build tool, not a runtime component: it is excluded from the distributed archive.
 */

$root = $argv[1] ?? \dirname(__DIR__);
$problems = [];
$checks = 0;

$read = static function (string $relative) use ($root): ?string {
    $path = $root.'/'.$relative;

    return is_file($path) ? (string) file_get_contents($path) : null;
};

$require = static function (string $label, bool $ok) use (&$problems, &$checks): void {
    ++$checks;

    if (!$ok) {
        $problems[] = $label;
    }
};

// --- 1. The pinned public-key ring must be usable ---------------------------------------------
$ring = $read('src/Manifest/TrustRing.php');
$require('TrustRing is missing', null !== $ring);

if (null !== $ring) {
    preg_match_all("/'keyB64' => '([^']*)'/", $ring, $keys);
    preg_match_all("/'fingerprintPrefix' => '([^']*)'/", $ring, $prints);

    $require('the production key ring is empty', [] !== ($keys[1] ?? []));
    $require('key/fingerprint entries do not pair up', \count($keys[1] ?? []) === \count($prints[1] ?? []));

    foreach ($keys[1] ?? [] as $index => $encoded) {
        $raw = base64_decode($encoded, true);
        $expected = $prints[1][$index] ?? '';

        $require(sprintf('key #%d is not a 32-byte Ed25519 public key', $index), false !== $raw && 32 === \strlen($raw));
        $require(
            sprintf('key #%d does not match its declared fingerprint', $index),
            false !== $raw && '' !== $expected && str_starts_with(hash('sha256', $raw), $expected),
        );
        $require(
            sprintf('key #%d looks like a placeholder', $index),
            false !== $raw && !preg_match('/^(AAAA|0000|TODO|CHANGE)/i', $encoded),
        );
    }
}

// --- 2. Outbound destinations stay fixed and exact ---------------------------------------------
$destinations = [
    'src/Transfer/ExchangeClient.php' => 'https://www.v-t.one/api/v1/verify',
    'src/EventListener/TelemetrySignals.php' => 'https://www.v-t.one/rest/api/v1/log-envoke',
];

foreach ($destinations as $relative => $endpoint) {
    $code = $read($relative);
    $require(sprintf('%s is missing', $relative), null !== $code);

    if (null !== $code) {
        $require(sprintf('%s does not pin %s', $relative, $endpoint), str_contains($code, "'".$endpoint."'"));
        $require(
            sprintf('%s allows redirects or disables TLS verification', $relative),
            str_contains($code, "'max_redirects' => 0") && !str_contains($code, "'verify_peer' => false"),
        );
    }
}

// --- 3. The signed updater path equals the served route ----------------------------------------
$routes = $read('src/Resources/config/routes.yaml');
$intake = $read('src/Controller/EntitlementIntakeController.php');
$path = '/rest/api/v1/migrator-license-updater';

$require('the updater route is not registered', null !== $routes && str_contains($routes, 'path: '.$path));
$require('the signed updater path differs from the served path', null !== $intake && str_contains($intake, "PATH = '".$path."'"));

// --- 4. The entitlement gate is not short-circuited --------------------------------------------
// The evaluator must expose only the immutable result: a convenience boolean there is one edit
// away from unlocking every protected operation (and has been, historically).
$evaluator = $read('src/Config/EntitlementEvaluator.php');
$require('the evaluator is missing', null !== $evaluator);
$require(
    'the evaluator exposes a single boolean gate',
    null === $evaluator || !str_contains($evaluator, 'public function isLicensed('),
);

$recovery = $read('src/Resources/recovery/_tcmig-recovery.php');
$require('the recovery panel is missing', null !== $recovery);
$require(
    'the recovery panel hardcodes its licence state',
    null === $recovery || !preg_match('/\$licensed\s*=\s*(true|false)\s*;/', $recovery),
);
$require(
    'the recovery panel does not consult the authoritative evaluator',
    null !== $recovery && str_contains($recovery, 'isLicensed()'),
);

// Both documented signals must still be emitted, as two distinct shapes.
$signals = $read('src/EventListener/TelemetrySignals.php');
$require('the invocation signal is not emitted', null !== $signals && str_contains($signals, "'project' => \$this->project, 'domain' => \$host"));
$require('the session module-entry signal is not emitted', null !== $signals && str_contains($signals, "'domain' => \$authenticated['domain'], 'key' => \$authenticated['key']"));

// Every protected boundary keeps its own server-side check.
$boundaries = [
    'src/Controller/MigratorController.php',
    'src/Controller/IngestController.php',
    'src/BackendModule/MigratorModule.php',
    'src/Job/JobRunner.php',
];

foreach ($boundaries as $relative) {
    $code = $read($relative);
    $require(sprintf('%s no longer enforces entitlement', $relative), null !== $code && str_contains($code, 'isLicensed()'));
}

// --- 4b. The licence surface stays native and route-free ---------------------------------------
// Regression guard for a real production defect: the controls used to be submit buttons carrying
// `formaction` to bundle-owned backend routes. When a browser (or Turbo) submitted through the
// enclosing form's own action instead, the click posted somewhere else entirely and failed
// silently, because the ambient CSRF token was valid for both targets. The controls are now named
// `<button type="submit">` elements with NO formaction — the exact mechanism Contao's own Save /
// Save-and-close buttons use — dispatched by one config.onsubmit callback that reads which name is
// present in the request. This must stay that way.
$require(
    'licence management must not own a backend route',
    null !== $routes && !str_contains($routes, 'migrator/license/'),
);

$dcaSurface = $read('contao/dca/tl_settings.php');
$entitlementFields = $read('src/EventListener/DataContainer/EntitlementFields.php');
$entitlementSummary = $read('src/BackendModule/EntitlementSummary.php');

$require('the licence DCA surface is missing', null !== $dcaSurface);
$require('the licence dispatcher is missing', null !== $entitlementFields);
$require('the licence render surface is missing', null !== $entitlementSummary);

$require(
    'the licence status field is not wired to an input_field_callback',
    null !== $dcaSurface
        && str_contains($dcaSurface, 'tcmig_license_status')
        && str_contains($dcaSurface, 'input_field_callback'),
);

// Comments legitimately explain, in prose, why onsubmit_callback must NOT be registered manually
// here — strip them first so that explanation cannot trip the very check it describes.
$dcaSurfaceCode = null !== $dcaSurface ? preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $dcaSurface) : null;
$require(
    'the DCA manually registers onsubmit_callback (would double-fire alongside #[AsCallback])',
    null !== $dcaSurfaceCode && !str_contains($dcaSurfaceCode, 'onsubmit_callback'),
);

$require(
    'onSubmit() is not wired via #[AsCallback(target: \'config.onsubmit\')]',
    null !== $entitlementFields && str_contains($entitlementFields, "target: 'config.onsubmit'"),
);

foreach (['tcmig_license_activate', 'tcmig_license_refresh', 'tcmig_license_remove'] as $name) {
    $require(
        sprintf('the dispatcher never checks for button "%s"', $name),
        null !== $entitlementFields && str_contains($entitlementFields, "request->has('".$name."')"),
    );

    $require(
        sprintf('no button is rendered with name="%s"', $name),
        null !== $entitlementSummary && str_contains($entitlementSummary, "'".$name."'"),
    );
}

foreach ([$entitlementFields, $entitlementSummary] as $code) {
    $require(
        'a licence control depends on formaction or JavaScript again',
        null === $code
            || (!str_contains($code, 'formaction="') && !str_contains($code, 'addEventListener')),
    );
}

// The key input must stay write-only: a literal, hardcoded empty value, never built from a
// variable — structurally incapable of echoing a stored or submitted key back into the page.
$require(
    'the stored licence key can be rendered back into the form',
    null !== $entitlementSummary && str_contains($entitlementSummary, 'name="tcmig_license_key" value=""'),
);

// The replaced implementations must be gone, not merely unregistered.
foreach (['src/BackendModule/LicensePanel.php', 'src/Controller/LicenseAdminController.php'] as $legacy) {
    $require(sprintf('legacy licence surface %s still exists', $legacy), !is_file($root.'/'.$legacy));
}

// --- 5. No development-only material in the built artefact --------------------------------------
// Only meaningful against an extracted artefact, so these run when a path was passed explicitly.
if (isset($argv[1])) {
    $require('captured licence fixtures must not be shipped', !is_dir($root.'/tests/Fixture'));
    $require('the test suite must not be shipped', !is_dir($root.'/tests'));
    $require('build tooling must not be shipped', !is_dir($root.'/tools'));
    $require('the private symbol map must not be shipped', !is_file($root.'/symbol-map.json'));
}

// --- report -------------------------------------------------------------------------------------
if ([] === $problems) {
    fwrite(\STDOUT, sprintf("release-guard: %d checks passed.\n", $checks));
    exit(0);
}

fwrite(\STDERR, sprintf("release-guard: %d of %d checks FAILED.\n", \count($problems), $checks));

foreach ($problems as $problem) {
    fwrite(\STDERR, '  - '.$problem."\n");
}

exit(1);
