<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Tests\Audit;

use Contao\CoreBundle\DependencyInjection\Attribute\AsCallback;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Yaml\Yaml;
use Vtinnovations\Migrator\BackendModule\EntitlementSummary;
use Vtinnovations\Migrator\Controller\EntitlementIntakeController;
use Vtinnovations\Migrator\EventListener\DataContainer\EntitlementFields;

/**
 * The static trace required before runtime acceptance: every rendered control resolves to one
 * registered handler, a permission scope and a CSRF guard — and there is exactly ONE
 * administrator-facing licensing surface in the whole bundle.
 *
 *   rendered control (a named <button type="submit"> inside Contao's own <form id="tl_settings">)
 *     -> button name (tcmig_license_activate | _refresh | _remove)
 *     -> EntitlementFields::onSubmit() — a single #[AsCallback(target: 'config.onsubmit')]
 *        dispatcher that reads which button's name is present in the submitted request
 *     -> permission: Contao's backend firewall (the form only renders/POSTs behind it)
 *     -> CSRF: the single REQUEST_TOKEN Contao's own form already carries
 *     -> EntitlementFields::{activate,refresh,remove}  (pure; a throw is reported via Message)
 *     -> EntitlementExchange (V-T.ONE transport or local removal)
 *     -> EntitlementStore (atomic authoritative state)
 *     -> Settings page reloads; EntitlementSummary shows the new state server-side
 *
 * The bundle owns NO route for licence management, and no button carries `formaction`: every
 * control submits to the form's own default action, exactly like Contao's own Save / Save-and-close
 * buttons (distinguished the same way — by which name is present in the POST body, see
 * DC_File::edit()'s `Input::post('saveNclose') !== null`). The previous implementation used
 * `formaction` to reach a bundle-owned route; when that override lost to the form's own action, the
 * click posted somewhere else entirely and failed silently, because both targets carried the same
 * valid ambient token. This surface cannot reproduce that failure because it never overrides where
 * or how the form submits.
 */
final class ActionWiringTest extends TestCase
{
    /** button name => handler method on EntitlementFields */
    private const ACTIONS = [
        'tcmig_license_activate' => 'activate',
        'tcmig_license_refresh' => 'refresh',
        'tcmig_license_remove' => 'remove',
    ];

    /**
     * @return array<string, array<string, mixed>>
     */
    private function routes(): array
    {
        $routes = Yaml::parseFile(\dirname(__DIR__, 2).'/src/Resources/config/routes.yaml');

        self::assertIsArray($routes);

        return $routes;
    }

    private function dca(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/contao/dca/tl_settings.php');
    }

    private function fieldsSource(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/src/EventListener/DataContainer/EntitlementFields.php');
    }

    private function summarySource(): string
    {
        return (string) file_get_contents(\dirname(__DIR__, 2).'/src/BackendModule/EntitlementSummary.php');
    }

    /**
     * Every button rendered by EntitlementSummary is dispatched by a real #[AsCallback] method on
     * EntitlementFields. Read via reflection rather than string matching for the callback binding,
     * so a renamed method or a typo'd target cannot pass by merely looking right in the source.
     */
    public function testEveryRenderedButtonResolvesToARegisteredDispatch(): void
    {
        $summary = $this->summarySource();
        $onSubmitBody = $this->onSubmitBody();
        $bound = $this->onSubmitCallbackTargets();

        self::assertArrayHasKey('config.onsubmit', $bound, 'no #[AsCallback] binds config.onsubmit on tl_settings.');
        self::assertSame('onSubmit', $bound['config.onsubmit'], 'config.onsubmit is bound to the wrong method.');

        $reflection = new \ReflectionMethod(EntitlementFields::class, 'onSubmit');
        self::assertTrue($reflection->isPublic(), 'onSubmit() must be public to be callable.');

        foreach (self::ACTIONS as $name => $method) {
            // The button itself: rendered with this exact name, as a real call to the shared
            // button() helper (never a decorative/dead literal).
            self::assertMatchesRegularExpression(
                "/->button\\(\\s*'".preg_quote($name, '/')."'/s",
                $summary,
                sprintf('no button is rendered with name="%s".', $name),
            );

            self::assertTrue(
                method_exists(EntitlementFields::class, $method),
                sprintf('%s::%s() does not exist.', EntitlementFields::class, $method),
            );

            self::assertStringContainsString(
                "request->has('".$name."')",
                $onSubmitBody,
                sprintf('onSubmit() never checks for button "%s".', $name),
            );

            self::assertStringContainsString(
                '$this->'.$method.'(',
                $onSubmitBody,
                sprintf('onSubmit() never dispatches to %s().', $method),
            );

            $actionReflection = new \ReflectionMethod(EntitlementFields::class, $method);
            self::assertTrue($actionReflection->isPublic(), sprintf('%s must be public.', $method));
        }
    }

    private function onSubmitBody(): string
    {
        preg_match('/function onSubmit\(.*?\n    \}/s', $this->fieldsSource(), $m);

        self::assertNotEmpty($m, 'onSubmit() not found');

        return $m[0];
    }

    /**
     * @return array<string, string> callback target => method name
     */
    private function onSubmitCallbackTargets(): array
    {
        $bound = [];

        foreach ((new \ReflectionClass(EntitlementFields::class))->getMethods() as $method) {
            foreach ($method->getAttributes(AsCallback::class) as $attribute) {
                $instance = $attribute->newInstance();

                self::assertSame('tl_settings', $instance->table, 'licence callbacks belong on tl_settings only');

                $bound[$instance->target] = $method->getName();
            }
        }

        return $bound;
    }

    /**
     * Regression guard for the production defect this surface replaced: no control may depend on a
     * bundle-owned route, a `formaction`/`formmethod` override, a nested `<form>`, or JavaScript
     * wiring. Contao's own Settings form is the entire submit path for every button.
     */
    public function testNoControlDependsOnFormactionRoutesOrJavaScript(): void
    {
        foreach ([$this->dca(), $this->fieldsSource(), $this->summarySource()] as $source) {
            // Strip comments first: they legitimately name `formaction` in prose (explaining the
            // defect this surface replaced), but must not themselves trip the assertion.
            $code = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $source);
            self::assertIsString($code);

            foreach (['formaction', 'formmethod', '<form', 'href="#"', 'javascript:', 'addEventListener', 'fetch(', 'XMLHttpRequest', 'data-turbo'] as $needle) {
                self::assertStringNotContainsString(
                    $needle,
                    $code,
                    sprintf('the licence surface must not use "%s" — Contao submits its own form.', $needle),
                );
            }
        }

        // And no route generation: there is no bundle route left to generate.
        self::assertStringNotContainsString('->generate(', $this->fieldsSource());
        self::assertStringNotContainsString('UrlGeneratorInterface', $this->fieldsSource());
    }

    /**
     * Every button is a genuine submit control (type="submit", inside the real form via
     * input_field_callback) with its own distinct name — never a decorative element and never two
     * controls sharing one name, which would make "which button was clicked" ambiguous.
     */
    public function testEveryButtonIsARealDistinctlyNamedSubmitControl(): void
    {
        $summary = $this->summarySource();

        self::assertStringContainsString('type="submit" name="%s"', $summary);

        $names = array_keys(self::ACTIONS);
        self::assertSame($names, array_unique($names), 'button names must be pairwise distinct');
    }

    /**
     * Licence management owns no route at all. The only remaining route carrying "license" is the
     * protocol-mandated inbound updater, which is server-to-server and must NOT sit behind the
     * backend firewall.
     */
    public function testLicenceManagementOwnsNoBackendRoute(): void
    {
        $routes = $this->routes();
        $licenceRoutes = array_filter(array_keys($routes), static fn (string $name): bool => str_contains($name, 'license'));
        sort($licenceRoutes);

        self::assertSame(
            ['tcmig_license_updater'],
            $licenceRoutes,
            'activation/refresh/removal must not have routes; they are DCA callbacks',
        );

        foreach ($routes as $name => $route) {
            self::assertStringNotContainsString(
                'migrator/license',
                (string) ($route['path'] ?? ''),
                sprintf('%s still exposes a backend licence path.', $name),
            );
        }
    }

    public function testCurrentStateIsRenderedServerSideAndNeverHangs(): void
    {
        $summary = $this->summarySource();

        self::assertStringContainsString('$evaluator->evaluate()', $summary);
        self::assertStringNotContainsString('Loading', $summary, 'state must not be a client-side placeholder');

        // Every model state the Trial/Free-and-Pro profile must distinguish has its own literal, so
        // a state can never render as an empty or ambiguous line.
        foreach ([
            'panel_status_unlicensed',
            'panel_status_trial',
            'panel_status_free',
            'panel_status_pro',
            'panel_status_pro_fallback',
            'panel_status_expired',
            'panel_status_invalid',
        ] as $key) {
            self::assertStringContainsString($key, $summary, sprintf('%s has no rendered state.', $key));
        }
    }

    /**
     * The key input is structurally incapable of echoing anything back: its `value=""` is a literal
     * in the source, not built from any variable, so there is no code path — past, present, or from
     * a careless future edit — that could interpolate a stored or submitted key into it.
     */
    public function testKeyInputValueIsALiteralEmptyStringNotABoundVariable(): void
    {
        $summary = $this->summarySource();

        self::assertStringContainsString(
            'name="tcmig_license_key" value=""',
            $summary,
            'the key input must render a literal empty value, not one built from a variable',
        );

        self::assertStringNotContainsString('authenticatedKeyAndDomain', $summary, 'the summary must never read the full key');
        self::assertStringNotContainsString('->key()', $summary);
    }

    public function testRefreshUsesTheStoredKeyAndTheCurrentVersion(): void
    {
        $exchange = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Config/EntitlementExchange.php');

        self::assertStringContainsString('$this->client->refresh($key, $domain, $current->version()', $exchange);
        self::assertStringNotContainsString('license_key\'', $exchange, 'the refresh key comes from the store, not the request');

        // The refresh method never accepts a key from the form.
        self::assertStringNotContainsString('$key', $this->refreshBody(), 'refresh must not accept a key from the form');

        $client = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Transfer/ExchangeClient.php');
        self::assertStringContainsString("'current_license_version' => \$currentVersion", $client);
    }

    private function refreshBody(): string
    {
        preg_match('/function refresh\(.*?\n    \}/s', $this->fieldsSource(), $m);

        self::assertNotEmpty($m, 'refresh() not found');

        return $m[0];
    }

    public function testUpdaterRouteMatchesTheProtocolPathAndTheSignedPath(): void
    {
        $routes = $this->routes();

        self::assertArrayHasKey('tcmig_license_updater', $routes);
        self::assertSame('/rest/api/v1/migrator-license-updater', $routes['tcmig_license_updater']['path']);
        self::assertSame(['POST'], $routes['tcmig_license_updater']['methods'], 'GET must 405, not 404');
        self::assertSame(
            EntitlementIntakeController::class,
            $routes['tcmig_license_updater']['defaults']['_controller'],
        );
        self::assertArrayNotHasKey(
            '_scope',
            $routes['tcmig_license_updater']['defaults'],
            'the server-to-server endpoint must not sit behind the backend firewall',
        );

        // The path bound into the request signature must be the path actually served.
        self::assertSame(
            $routes['tcmig_license_updater']['path'],
            (new \ReflectionClass(EntitlementIntakeController::class))->getConstant('PATH'),
        );
    }

    public function testTheSettingsSectionIsTheOnlyRegisteredLicensingSurface(): void
    {
        $dca = $this->dca();

        self::assertStringContainsString('tcmig_license_status', $dca);
        self::assertStringContainsString('input_field_callback', $dca);
        self::assertStringContainsString('EntitlementSummary::class', $dca);
        self::assertStringContainsString("applyToPalette('default', 'tl_settings')", $dca);
        self::assertTrue(method_exists(EntitlementSummary::class, 'render'));

        foreach (['en', 'de'] as $language) {
            $labels = (string) file_get_contents(\dirname(__DIR__, 2).'/contao/languages/'.$language.'/tl_settings.php');

            self::assertStringContainsString(
                "\$GLOBALS['TL_LANG']['tl_settings']['vtone_licence_legend'] = 'V-T.ONE Licence management';",
                $labels,
                'the shared legend headline must be exactly "V-T.ONE Licence management" in every language',
            );

            self::assertStringContainsString(
                "'tcmig_license_status'] = ['Migrator',",
                $labels,
                sprintf('%s has no %s field label opening with the product name.', 'tcmig_license_status', $language),
            );
        }
    }

    public function testNoLegacyOrDuplicateLicensingSurfaceRemains(): void
    {
        $root = \dirname(__DIR__, 2);

        // The replaced implementation is gone, not merely unregistered.
        self::assertFileDoesNotExist($root.'/src/BackendModule/LicensePanel.php');
        self::assertFileDoesNotExist($root.'/src/Controller/LicenseAdminController.php');

        // No second DCA registers a licence field, and the backend module never collects a key.
        foreach (glob($root.'/contao/dca/*.php') ?: [] as $file) {
            if (str_ends_with($file, 'tl_settings.php')) {
                continue;
            }

            self::assertStringNotContainsStringIgnoringCase('license', (string) file_get_contents($file), $file);
        }

        $config = (string) file_get_contents($root.'/contao/config/config.php');
        self::assertStringNotContainsStringIgnoringCase('license', $config, 'no standalone licence module may be registered');

        $module = (string) file_get_contents($root.'/src/BackendModule/MigratorModule.php');
        self::assertStringNotContainsString('name="license_key"', $module, 'the module must not offer a second key form');
        self::assertStringContainsString('do=settings', $module, 'the module points at the single authoritative surface');

        // Nothing anywhere still references the removed classes.
        $stale = ['LicensePanel', 'LicenseAdminController'];

        foreach ($this->sourceFiles() as $file => $code) {
            foreach ($stale as $needle) {
                self::assertStringNotContainsString($needle, $code, sprintf('%s still references %s.', $file, $needle));
            }
        }

        $services = (string) file_get_contents($root.'/src/Resources/config/services.yaml');
        self::assertStringNotContainsString('LicenseAdminController', $services);
        self::assertStringContainsString('EntitlementFields', $services, 'the DCA dispatcher must be a registered service');

        // The DCA must not ALSO register the callback manually — #[AsCallback] autoconfigure already
        // does, and a duplicate registration would run onSubmit() twice per click. Comments
        // legitimately explain this in prose, so strip them before checking for a real entry.
        $dcaCode = preg_replace(['#/\*.*?\*/#s', '#//[^\n]*#'], '', $this->dca());
        self::assertIsString($dcaCode);
        self::assertStringNotContainsString(
            'onsubmit_callback',
            $dcaCode,
            'onsubmit_callback must not be registered manually in the DCA file — it would double-fire alongside #[AsCallback]',
        );
    }

    public function testTheModuleEntrySignalIsOwnedByTheProtectedModuleOnly(): void
    {
        $owners = [];

        foreach ($this->sourceFiles() as $file => $code) {
            if (str_contains($code, '->onModuleEntry(')) {
                $owners[] = basename($file);
            }
        }

        self::assertSame(['MigratorModule.php'], $owners, 'exactly one caller may emit the session signal');
    }

    /**
     * @return array<string, string>
     */
    private function sourceFiles(): array
    {
        $root = \dirname(__DIR__, 2).'/src';
        $files = [];
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS));

        foreach ($it as $file) {
            if ($file->isFile() && 'php' === $file->getExtension()) {
                $files[substr($file->getPathname(), \strlen($root) + 1)] = (string) file_get_contents($file->getPathname());
            }
        }

        self::assertNotEmpty($files);

        return $files;
    }
}
