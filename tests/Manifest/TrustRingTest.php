<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Tests\Manifest;

use PHPUnit\Framework\TestCase;
use Vtinnovations\Migrator\Manifest\TrustRing;

/**
 * The pinned public-key ring is a release blocker when empty, placeholder-only or structurally
 * invalid: a build that ships such a ring can never verify a real V-T.ONE response.
 */
final class TrustRingTest extends TestCase
{
    private TrustRing $ring;

    protected function setUp(): void
    {
        $this->ring = new TrustRing();
    }

    public function testProductionRingIsNotEmpty(): void
    {
        self::assertTrue($this->ring->isReady(), 'A distributable build must pin at least one valid public key.');
    }

    /**
     * The server advertises the active key either by its label or by the first 16 hex chars of its
     * SHA-256 fingerprint; both selectors must resolve to the same approved key bytes.
     */
    public function testActiveKeyIdsResolveToTheSameApprovedKey(): void
    {
        $byLabel = $this->ring->rawKey('vtone-2026a', TrustRing::PURPOSE_ENVELOPE);
        $byFingerprint = $this->ring->rawKey('edcd614e70c59ce0', TrustRing::PURPOSE_ENVELOPE);

        self::assertNotNull($byLabel);
        self::assertNotNull($byFingerprint);
        self::assertSame(32, \strlen($byLabel), 'Ed25519 public keys are exactly 32 raw bytes.');
        self::assertSame($byLabel, $byFingerprint);
        self::assertSame('edcd614e70c59ce0', substr(hash('sha256', $byLabel), 0, 16));
    }

    public function testKeyIsApprovedForAllThreeSignatureDomains(): void
    {
        foreach ([TrustRing::PURPOSE_RECORD, TrustRing::PURPOSE_ENVELOPE, TrustRing::PURPOSE_REQUEST] as $purpose) {
            self::assertNotNull($this->ring->rawKey('vtone-2026a', $purpose), $purpose);
        }
    }

    public function testUnknownKeyIdFailsClosed(): void
    {
        self::assertNull($this->ring->rawKey('vtone-2027z', TrustRing::PURPOSE_ENVELOPE));
        self::assertNull($this->ring->rawKey('', TrustRing::PURPOSE_ENVELOPE));
    }

    public function testUnapprovedPurposeFailsClosed(): void
    {
        self::assertNull($this->ring->rawKey('vtone-2026a', 'telemetry'));
    }

    public function testKeyIsNotUsableBeforeItsActivationTime(): void
    {
        // Activation time is 0 for the 2026a profile, so no timestamp may exclude it; a negative
        // "now" is the only value before it and proves the window is actually applied.
        self::assertNotNull($this->ring->rawKey('vtone-2026a', TrustRing::PURPOSE_RECORD, 0));
        self::assertNull($this->ring->rawKey('vtone-2026a', TrustRing::PURPOSE_RECORD, -1));
    }

    public function testUsableKeysOnlyReturnsStructurallyValidMaterial(): void
    {
        $keys = $this->ring->usableKeys(TrustRing::PURPOSE_RECORD, time());

        self::assertNotEmpty($keys);

        foreach ($keys as $keyId => $raw) {
            self::assertNotSame('', $keyId);
            self::assertSame(32, \strlen($raw));
        }
    }
}
