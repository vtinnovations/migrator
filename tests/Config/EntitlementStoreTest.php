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

namespace Vtinnovations\Migrator\Tests\Config;

use PHPUnit\Framework\TestCase;
use Vtinnovations\Migrator\Config\EntitlementStore;
use Vtinnovations\Migrator\Tests\TestKit;

/**
 * Atomic activation of the authoritative pair. The exact licence bytes and their signed envelope
 * live in one file so a crash can never leave them mismatched, and every mutation runs under an
 * exclusive lock in a private location that never comes from a request.
 */
final class EntitlementStoreTest extends TestCase
{
    use TestKit;

    private EntitlementStore $store;

    /** @var array<string, mixed> */
    private array $envelope = [
        'project' => 'Migrator',
        'project_slug' => 'migrator',
        'license_version' => 7,
        'license_md5' => 'ignored-by-the-store',
        'key_id' => 'vtone-2026a',
        'signature_algorithm' => 'ed25519',
        'signature' => 'envelope-signature',
    ];

    protected function setUp(): void
    {
        $this->store = new EntitlementStore($this->paths());
    }

    protected function tearDown(): void
    {
        $this->cleanTmp();
    }

    public function testAbsentStateReadsAsNull(): void
    {
        self::assertNull($this->store->loadRaw());
        self::assertFalse($this->store->exists());
    }

    public function testSaveRoundTripsTheExactBytesNotAReserializedForm(): void
    {
        $bytes = '{"schema_version":2,"license_key":"AAAAA-BBBBB","trailing":"  spaced  "}';

        $this->store->withLock(function () use ($bytes): void {
            $this->store->save($bytes, $this->envelope, 'example.com');
        });

        $raw = $this->store->loadRaw();

        self::assertNotNull($raw);
        self::assertSame($bytes, $raw['bytes'], 'the byte-for-byte payload must survive persistence');
        self::assertSame(md5($bytes), md5($raw['bytes']));
        self::assertSame('example.com', $raw['matchedDomain']);
        self::assertSame($this->envelope, $raw['envelope']);
    }

    public function testSaveReplacesTheStateAndKeepsBytesAndEnvelopeTogether(): void
    {
        $this->store->withLock(fn () => $this->store->save('{"v":1}', $this->envelope, 'a.example.com'));

        $newer = ['license_version' => 8] + $this->envelope;
        $this->store->withLock(fn () => $this->store->save('{"v":2}', $newer, 'b.example.com'));

        $raw = $this->store->loadRaw();

        self::assertNotNull($raw);
        self::assertSame('{"v":2}', $raw['bytes']);
        self::assertSame(8, $raw['envelope']['license_version']);
        self::assertSame('b.example.com', $raw['matchedDomain']);
    }

    public function testRemoveRestoresTheUnlicensedDefaultImmediately(): void
    {
        $this->store->withLock(fn () => $this->store->save('{"v":1}', $this->envelope, 'example.com'));
        self::assertTrue($this->store->exists());

        $this->store->withLock(fn () => $this->store->remove());

        self::assertFalse($this->store->exists());
        self::assertNull($this->store->loadRaw());
    }

    public function testConcurrentWriterCannotAcquireTheLock(): void
    {
        $inner = $this->store->withLock(fn () => $this->store->withLock(static fn (): string => 'entered'));

        self::assertNull($inner, 'a second holder must be refused rather than write concurrently');
    }

    public function testLockIsReleasedAfterTheCallbackThrows(): void
    {
        try {
            $this->store->withLock(static function (): void {
                throw new \RuntimeException('boom');
            });
            self::fail('the exception should propagate');
        } catch (\RuntimeException) {
            // expected
        }

        self::assertSame('reacquired', $this->store->withLock(static fn (): string => 'reacquired'));
    }

    public function testTamperedStateFileIsReportedAsUnreadableRatherThanTrusted(): void
    {
        $this->store->withLock(fn () => $this->store->save('{"v":1}', $this->envelope, 'example.com'));

        file_put_contents($this->paths()->entitlementFile(), 'not json at all');

        self::assertNull($this->store->loadRaw());
    }
}
