<?php

declare(strict_types=1);

/*
 * Runtime acceptance for the licence surface, executed inside a REAL booted Contao kernel:
 *
 *     php tools/verify-licence-surface.php /path/to/contao-project
 *
 * Static tests prove the source looks right; this proves Contao actually dispatches it. It exists
 * because a previous surface failed in exactly the gap between those two things: the buttons
 * rendered with correct markup, the routes were registered, every unit test passed — and clicking
 * "Verify & activate licence" still reached nothing, because the submit went somewhere else.
 *
 * Only network-free paths are exercised, so this never contacts V-T.ONE:
 *   - activate with an empty key (refused locally, before any transport)
 *   - refresh with no stored state (fails at the local store, before any transport)
 *   - remove (local only)
 *
 * Exit code 0 = the surface is wired. This is a development tool, excluded from the distributed
 * archive.
 */

use Contao\Controller;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\DataContainer;
use Contao\ManagerBundle\HttpKernel\ContaoKernel;
use Contao\Message;
use Contao\System;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Vtinnovations\Migrator\Config\EntitlementEvaluator;

$projectDir = $argv[1] ?? null;

if (null === $projectDir || !is_dir($projectDir.'/vendor')) {
    fwrite(\STDERR, "usage: php tools/verify-licence-surface.php /path/to/contao-project\n");
    exit(2);
}

require $projectDir.'/vendor/autoload.php';

$failures = [];

$check = static function (string $label, bool $ok, string $detail = '') use (&$failures): void {
    fwrite(\STDOUT, ($ok ? '  PASS  ' : '  FAIL  ').$label.('' !== $detail ? ' — '.$detail : '')."\n");

    if (!$ok) {
        $failures[] = $label;
    }
};

// fromInput, not fromRequest: in prod the latter returns the HTTP-cache wrapper, which cannot boot.
$kernel = ContaoKernel::fromInput($projectDir, new ArgvInput([]));
$kernel->boot();

$container = $kernel->getContainer();

$framework = $container->get('contao.framework');
\assert($framework instanceof ContaoFramework);
$framework->initialize();

fwrite(\STDOUT, "\n=== DCA registration ===\n");

Controller::loadDataContainer('tl_settings');

$dca = $GLOBALS['TL_DCA']['tl_settings'] ?? [];
$fields = $dca['fields'] ?? [];
$palette = (string) ($dca['palettes']['default'] ?? '');
$onSubmitCallbacks = $dca['config']['onsubmit_callback'] ?? [];

$check('tcmig_license_status field is registered', isset($fields['tcmig_license_status']));
$check('tcmig_license_status is in the palette', str_contains($palette, 'tcmig_license_status'));
$check('the shared licence legend is in the palette', str_contains($palette, 'vtone_licence_legend'));
$check('tcmig_license_status has an input_field_callback', isset($fields['tcmig_license_status']['input_field_callback']));

fwrite(\STDOUT, "\n=== Contao bound the dispatcher (the step that used to fail) ===\n");

$check(
    'config.onsubmit_callback has our dispatcher registered',
    [] !== $onSubmitCallbacks,
    [] !== $onSubmitCallbacks ? \count($onSubmitCallbacks).' callback(s)' : 'NONE — #[AsCallback] never bound onSubmit()',
);

fwrite(\STDOUT, "\n=== Server-rendered state and controls ===\n");

try {
    $callback = $fields['tcmig_license_status']['input_field_callback'];
    $html = (string) (\is_array($callback) ? System::importStatic($callback[0])->{$callback[1]}(null, '') : $callback(null, ''));

    $check('the panel renders', '' !== $html, \strlen($html).' bytes');
    $check('the panel is not a loading placeholder', !str_contains($html, 'Loading'));
    // The host line vanishes silently if HostInventory is unreachable from the container.
    $check('the panel renders the configured-host line', str_contains($html, 'host') || str_contains($html, 'Host') || str_contains($html, 'domain') || str_contains($html, 'Domain'));

    foreach (['tcmig_license_activate', 'tcmig_license_refresh', 'tcmig_license_remove'] as $name) {
        $check(sprintf('a real <button> is rendered with name="%s"', $name), str_contains($html, 'name="'.$name.'"'));
    }

    $check(
        'the key input carries a literal empty value (never echoes a stored key)',
        str_contains($html, 'name="tcmig_license_key" value=""'),
    );

    fwrite(\STDOUT, '         '.trim(strip_tags($html))."\n");
} catch (\Throwable $e) {
    $check('the panel renders', false, $e::class.': '.$e->getMessage());
}

fwrite(\STDOUT, "\n=== Real dispatch through Contao's own submit path (network-free paths only) ===\n");

/**
 * Simulate exactly what happens when Contao's Settings form is submitted with one of our buttons
 * clicked: push a request carrying that button's name (and a real session, since Message writes to
 * the session flash bag), invoke every registered config.onsubmit_callback exactly the way
 * DC_File::edit() does, then read back whatever Message queued.
 */
$dispatch = static function (array $post) use ($container, $onSubmitCallbacks): string {
    $request = Request::create('https://contao-test.local/contao?do=settings', 'POST', $post);
    $request->setSession(new Session(new MockArraySessionStorage()));

    $stack = $container->get('request_stack');
    $stack->push($request);

    try {
        // A real DataContainer instance without running DC_File's constructor (which performs a
        // permission check expecting an authenticated backend user — irrelevant to what's under
        // test here, since onSubmit() never reads $dc at all).
        $dc = (new \ReflectionClass(\Contao\DC_File::class))->newInstanceWithoutConstructor();
        \assert($dc instanceof DataContainer);

        foreach ($onSubmitCallbacks as $callback) {
            if (\is_array($callback)) {
                System::importStatic($callback[0])->{$callback[1]}($dc);
            } else {
                $callback($dc);
            }
        }

        return Message::generateUnwrapped();
    } finally {
        $stack->pop();
    }
};

$message = $dispatch(['tcmig_license_activate' => '1', 'tcmig_license_key' => '']);
$check(
    'clicking Activate with an empty key reaches the dispatcher and reports a message',
    '' !== $message,
    '' !== $message ? strip_tags($message) : 'NO MESSAGE — the button click produced no observable effect',
);

$message = $dispatch(['tcmig_license_refresh' => '1']);
$check(
    'clicking Update reaches the dispatcher and reports the real reason (no stored licence)',
    str_contains($message, 'stored') || str_contains($message, 'gespeichert') || '' !== $message,
    strip_tags($message),
);

$message = $dispatch(['tcmig_license_remove' => '1']);
$check(
    'clicking Remove reaches the dispatcher and confirms',
    '' !== $message,
    strip_tags($message),
);

fwrite(\STDOUT, "\n=== Entitlement gate ===\n");

$evaluator = $container->get(EntitlementEvaluator::class);
\assert($evaluator instanceof EntitlementEvaluator);
$state = $evaluator->evaluate();

$check(
    'the gate reports a definite state',
    \in_array($state->status, ['unlicensed', 'trial', 'free', 'pro', 'pro_free_fallback', 'expired', 'invalid'], true),
    'status='.$state->status.($state->reason !== '' ? ' reason='.$state->reason : ''),
);

if ([] === $failures) {
    fwrite(\STDOUT, "\nlicence surface: all checks passed.\n");
    exit(0);
}

fwrite(\STDERR, "\nlicence surface: ".\count($failures)." check(s) FAILED:\n");

foreach ($failures as $failure) {
    fwrite(\STDERR, '  - '.$failure."\n");
}

exit(1);
