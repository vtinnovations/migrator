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

namespace Vtinnovations\Migrator\Tests\Audit;

use PHPUnit\Framework\TestCase;

/**
 * Source-layout concealment audit. The entitlement flow must not present itself as one obvious,
 * removable subsystem: no advertising directory or namespace, no all-in-one class holding endpoint
 * + keys + digests + signatures + domain policy + persistence, and no single gate whose removal
 * unlocks every protected operation.
 */
final class SourceLayoutTest extends TestCase
{
    private const ADVERTISING = ['Licensing', 'License', 'Licence', 'Protection', 'Integrity', 'AntiTamper', 'DRM', 'VtOne', 'VTone'];

    public function testNoAdvertisingDirectoryExists(): void
    {
        $root = \dirname(__DIR__, 2).'/src';
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($it as $entry) {
            if (!$entry->isDir()) {
                continue;
            }

            self::assertNotContains(
                $entry->getBasename(),
                self::ADVERTISING,
                sprintf('"%s" advertises the subsystem; distribute it through native seams instead.', $entry->getPathname()),
            );
        }
    }

    public function testNoAdvertisingNamespaceExists(): void
    {
        foreach ($this->sourceFiles() as $file => $code) {
            if (!preg_match('/^namespace\s+([^;]+);/m', $code, $m)) {
                continue;
            }

            foreach (self::ADVERTISING as $segment) {
                self::assertStringNotContainsString(
                    '\\'.$segment,
                    $m[1].'\\',
                    sprintf('%s declares an advertising namespace.', $file),
                );
            }
        }
    }

    /**
     * The sensitive responsibilities live in at least four different existing architectural seams.
     */
    public function testResponsibilitiesAreDistributedAcrossSeams(): void
    {
        $responsibilities = [
            'fixed endpoints' => 'src/Transfer/ExchangeClient.php',
            'invocation transport' => 'src/EventListener/TelemetrySignals.php',
            'public key ring' => 'src/Manifest/TrustRing.php',
            'signature verification' => 'src/Manifest/DetachedVerifier.php',
            'canonical form' => 'src/Manifest/CanonicalJson.php',
            'packet opening + md5' => 'src/Transfer/ExchangeResponseReader.php',
            'updater authentication' => 'src/Transfer/UpdaterRequestVerifier.php',
            'replay journal' => 'src/Transfer/UpdaterJournal.php',
            'atomic persistence' => 'src/Config/EntitlementStore.php',
            'domain policy' => 'src/Config/HostInventory.php',
            'entitlement result' => 'src/Config/EntitlementState.php',
            'public route' => 'src/Controller/EntitlementIntakeController.php',
        ];

        $directories = [];

        foreach ($responsibilities as $what => $relative) {
            $path = \dirname(__DIR__, 2).'/'.$relative;
            self::assertFileExists($path, $what);
            $directories[\dirname($relative)] = true;
        }

        self::assertGreaterThanOrEqual(4, \count($directories), 'the flow is concentrated in too few directories');
    }

    /**
     * No single file may combine the sensitive concerns.
     */
    public function testNoAllInOneSecurityClass(): void
    {
        $markers = [
            'endpoint' => 'https://www.v-t.one',
            'key material' => 'ED25519_PUBLIC_BYTES',
            'md5 tripwire' => 'md5(',
            'signature verification' => 'sodium_crypto_sign_verify_detached',
            'domain policy' => 'firstIntersection',
            'persistence' => 'entitlementFile(',
        ];

        foreach ($this->sourceFiles() as $file => $code) {
            $hits = [];

            foreach ($markers as $what => $needle) {
                if (str_contains($code, $needle)) {
                    $hits[] = $what;
                }
            }

            self::assertLessThan(
                4,
                \count($hits),
                sprintf('%s concentrates %s.', $file, implode(' + ', $hits)),
            );
        }
    }

    /**
     * The gate is not one convenience boolean: the evaluator exposes only the immutable result, and
     * enforcement is repeated at each protected boundary — including inside the job pipeline, so a
     * bypassed request-level check still cannot advance a migration.
     */
    public function testNoSingleBooleanGate(): void
    {
        $evaluator = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Config/EntitlementEvaluator.php');

        self::assertStringNotContainsString(
            'public function isLicensed(',
            $evaluator,
            'a single evaluator-level boolean is one edit away from unlocking everything',
        );

        $gated = [
            'src/Controller/MigratorController.php',
            'src/Controller/IngestController.php',
            'src/BackendModule/MigratorModule.php',
            'src/Job/JobRunner.php',
            'src/Resources/recovery/_tcmig-recovery.php',
        ];

        foreach ($gated as $relative) {
            $code = (string) file_get_contents(\dirname(__DIR__, 2).'/'.$relative);

            self::assertStringContainsString(
                'isLicensed()',
                $code,
                sprintf('%s must enforce entitlement at its own boundary.', $relative),
            );
        }
    }

    /**
     * The public updater handler stays thin: it may not write files or build paths from the request.
     */
    public function testUpdaterHandlerIsThin(): void
    {
        $code = (string) file_get_contents(\dirname(__DIR__, 2).'/src/Controller/EntitlementIntakeController.php');

        foreach (['file_put_contents', 'fopen(', 'rename(', 'unlink(', 'eval(', 'include ', 'require '] as $needle) {
            self::assertStringNotContainsString($needle, $code, 'the updater route must delegate persistence.');
        }

        self::assertLessThan(150, substr_count($code, "\n"), 'the updater route should stay small.');
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
