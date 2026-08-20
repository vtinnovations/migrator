<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Tests;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Schema\AbstractSchemaManager;
use Vtinnovations\Migrator\Config\EntitlementRecord;
use Vtinnovations\Migrator\Config\HostInventory;
use Vtinnovations\Migrator\Config\HostNormalizer;
use Vtinnovations\Migrator\Config\Paths;
use Vtinnovations\Migrator\Preflight\RootPageScanner;

/**
 * Shared helpers. Deliberately contains NO signed V-T.ONE fixture: the signing keys are private to
 * V-T.ONE, so a positive signature vector can only come from an approved captured response
 * (see {@see SignedFixture}). Nothing here fabricates one.
 */
trait TestKit
{
    private ?string $tmpRoot = null;

    /** A throwaway scratch dir; removed by {@see self::cleanTmp()}. */
    protected function paths(): Paths
    {
        $this->tmpRoot ??= sys_get_temp_dir().'/tcmig-test-'.bin2hex(random_bytes(6));

        return new Paths($this->tmpRoot);
    }

    protected function cleanTmp(): void
    {
        if (null === $this->tmpRoot || !is_dir($this->tmpRoot)) {
            return;
        }

        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->tmpRoot, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($it as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }

        @rmdir($this->tmpRoot);
        $this->tmpRoot = null;
    }

    /**
     * The trusted domain inventory exactly as production derives it: from root-page configuration
     * (tl_page.dns), never from a request header. The database is stubbed rather than driven so the
     * test needs no DB driver — the point under test is the binding rule, not Doctrine.
     *
     * @param list<string> $rootDomains
     */
    protected function inventoryFor(array $rootDomains): HostInventory
    {
        return new HostInventory(new RootPageScanner($this->pageConnection($rootDomains)), new HostNormalizer());
    }

    /**
     * @param list<string> $rootDomains
     */
    protected function pageConnection(array $rootDomains): Connection
    {
        $rows = [];
        $id = 1;

        foreach ($rootDomains as $dns) {
            $rows[] = ['id' => $id++, 'dns' => $dns, 'useSSL' => 1, 'language' => 'en', 'fallback' => 1];
        }

        $schema = $this->createMock(AbstractSchemaManager::class);
        $schema->method('tablesExist')->willReturn(true);

        $connection = $this->createMock(Connection::class);
        $connection->method('createSchemaManager')->willReturn($schema);
        $connection->method('fetchAllAssociative')->willReturn($rows);

        return $connection;
    }

    /**
     * An UNSIGNED licence document usable for policy/value-object tests only. Anything that must
     * verify a signature uses {@see SignedFixture} instead.
     *
     * @param array<string, mixed> $overrides
     */
    protected function document(array $overrides = []): array
    {
        return array_merge([
            'schema_version' => 2,
            'project' => 'Migrator',
            'project_slug' => 'migrator',
            'license_key' => 'AAAAA-BBBBB-CCCCC-DDDDD',
            'license_domain' => 'example.com',
            'license_domains' => ['example.com', 'staging.example.com'],
            'license_max_domains' => 3,
            'license_package' => 'pro',
            'license_features' => [],
            'license_version' => 7,
            'license_issued_at' => 1784000000,
            'license_starts_at' => 1784000000,
            'license_expires_at' => 1815536000,
            'license_lifetime' => false,
            'license_verified_at' => 1784880547,
            'free_available' => true,
            'signature' => 'unsigned-test-document',
            'validation_status' => 'valid',
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    protected function record(array $overrides = []): EntitlementRecord
    {
        $document = $this->document($overrides);
        $bytes = json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        self::assertIsString($bytes);

        return EntitlementRecord::fromBytes($bytes, [
            'project' => 'Migrator',
            'project_slug' => 'migrator',
            'license_version' => $document['license_version'],
            'license_md5' => md5($bytes),
            'generated_at' => 1784880547,
            'key_id' => 'vtone-2026a',
            'signature_algorithm' => 'ed25519',
            'signature' => 'unsigned-test-envelope',
        ]);
    }
}
