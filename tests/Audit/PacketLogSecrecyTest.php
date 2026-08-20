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
use Psr\Log\LoggerInterface;
use Vtinnovations\Migrator\Config\EntitlementEvaluator;
use Vtinnovations\Migrator\Config\EntitlementExchange;
use Vtinnovations\Migrator\Config\EntitlementStore;
use Vtinnovations\Migrator\Controller\EntitlementIntakeController;
use Vtinnovations\Migrator\EventListener\DataContainer\EntitlementFields;
use Vtinnovations\Migrator\EventListener\TelemetrySignals;
use Vtinnovations\Migrator\Manifest\DetachedVerifier;
use Vtinnovations\Migrator\Manifest\TrustRing;
use Vtinnovations\Migrator\Transfer\ExchangeClient;
use Vtinnovations\Migrator\Transfer\ExchangeResponseReader;
use Vtinnovations\Migrator\Transfer\UpdaterJournal;
use Vtinnovations\Migrator\Transfer\UpdaterRequestVerifier;

/**
 * Packet-log secrecy is a release blocker, not a best-effort recommendation: ordinary logs and
 * browser responses must never carry packets, nonces, licence keys (or their length/hash), raw or
 * Base64 payloads, MD5s, signatures or request/response digests.
 */
final class PacketLogSecrecyTest extends TestCase
{
    private const FORBIDDEN = [
        'request_packet', 'response_packet', 'request_body', 'response_body',
        'license_payload_b64', 'license_md5', 'request_sha256', 'response_sha256',
        'licence_key_sha256', 'license_key_sha256', 'licence_key_length', 'license_key_length',
        'license_key', 'licence_key', 'nonce', 'signature', 'X-VT-Signature',
    ];

    /**
     * The classes that touch packet material take no logger at all: there is nothing for a future
     * edit to leak through. This is stronger than reviewing individual log statements.
     */
    public function testPacketHandlingClassesCannotLog(): void
    {
        $classes = [
            ExchangeClient::class,
            ExchangeResponseReader::class,
            UpdaterRequestVerifier::class,
            UpdaterJournal::class,
            TelemetrySignals::class,
            TrustRing::class,
            DetachedVerifier::class,
            EntitlementStore::class,
            EntitlementEvaluator::class,
            EntitlementExchange::class,
            EntitlementFields::class,
            EntitlementIntakeController::class,
        ];

        foreach ($classes as $class) {
            $constructor = (new \ReflectionClass($class))->getConstructor();

            foreach ($constructor?->getParameters() ?? [] as $parameter) {
                $type = $parameter->getType();
                $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';

                self::assertNotSame(
                    LoggerInterface::class,
                    $name,
                    sprintf('%s must not receive a logger: it handles packet material.', $class),
                );
            }
        }
    }

    /**
     * Nothing anywhere in the bundle may put a forbidden field into a log statement.
     */
    public function testNoLogStatementCarriesPacketMaterial(): void
    {
        $pattern = '/->(?:log|debug|info|notice|warning|error|critical|alert|emergency)\s*\(.*?\);/s';

        foreach ($this->sourceFiles() as $file => $code) {
            preg_match_all($pattern, $code, $matches);

            foreach ($matches[0] as $statement) {
                foreach (self::FORBIDDEN as $needle) {
                    self::assertStringNotContainsStringIgnoringCase(
                        $needle,
                        $statement,
                        sprintf('%s logs "%s".', $file, $needle),
                    );
                }
            }
        }
    }

    /**
     * Debug side channels are as bad as a logger.
     */
    public function testNoDebugOutputOfPacketMaterial(): void
    {
        foreach ($this->sourceFiles() as $file => $code) {
            foreach (['var_dump(', 'print_r(', 'error_log(', 'file_put_contents(\'php://', 'fwrite(STDERR'] as $needle) {
                self::assertStringNotContainsString($needle, $code, sprintf('%s uses a debug side channel.', $file));
            }
        }
    }

    /**
     * The administrator-facing failure text is a fixed generic set plus the server's own
     * customer-facing message; internal categories never reach the screen.
     */
    public function testAdminSurfaceNeverEchoesTheSubmittedKey(): void
    {
        // The submitted key is forwarded to the exchange and never interpolated into any output.
        $fields = (string) file_get_contents(\dirname(__DIR__, 2).'/src/EventListener/DataContainer/EntitlementFields.php');

        foreach (['{$key}', '\'.$key', '$key.\'', 'sprintf(\'%s\', $key'] as $needle) {
            self::assertStringNotContainsString($needle, $fields, 'the DCA dispatcher echoes the licence key.');
        }

        // The key input is not a Contao widget bound to stored state at all: EntitlementSummary
        // renders it as raw HTML with a literal, hardcoded empty value — there is no code path that
        // could interpolate a stored or submitted key into it.
        $summary = (string) file_get_contents(\dirname(__DIR__, 2).'/src/BackendModule/EntitlementSummary.php');
        self::assertStringContainsString(
            'name="tcmig_license_key" value=""',
            $summary,
            'the key input must render a literal empty value',
        );
        self::assertStringNotContainsString('authenticatedKeyAndDomain', $summary, 'the summary must never read the full key');
        self::assertStringNotContainsString('->key()', $summary);
    }

    /**
     * @return array<string, string> path => source
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
