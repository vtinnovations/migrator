<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Tests\Transfer;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vtinnovations\Migrator\Manifest\DetachedVerifier;
use Vtinnovations\Migrator\Manifest\TrustRing;
use Vtinnovations\Migrator\Transfer\UpdaterRequestVerifier;

/**
 * Inbound updater authentication. A claimed origin, referer or source IP proves nothing — only the
 * `vt-one/request-sig-v1` signature does, and every malformed or stale delivery fails closed.
 *
 * A POSITIVE vector cannot be produced here: it requires the V-T.ONE private key. The approved
 * captured request in tests/Fixture/ drives that assertion (see SignedPacketTest).
 */
final class UpdaterRequestVerifierTest extends TestCase
{
    private const PATH = '/rest/api/v1/migrator-license-updater';
    private const NOW = 1800000000;

    private UpdaterRequestVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new UpdaterRequestVerifier(new TrustRing(), new DetachedVerifier());
    }

    /**
     * @param array<string, mixed> $overrides
     *
     * @return array{requestId:string, timestamp:int, nonce:string, keyId:string, signature:string}
     */
    private function headers(array $overrides = []): array
    {
        return array_merge([
            'requestId' => 'req-123',
            'timestamp' => self::NOW,
            'nonce' => 'nonce-abc',
            'keyId' => 'vtone-2026a',
            'signature' => base64_encode(str_repeat("\1", 64)),
        ], $overrides);
    }

    public function testForgedSignatureIsRejected(): void
    {
        self::assertFalse($this->verifier->verify('POST', self::PATH, $this->headers(), '{"action":"license_update"}', self::NOW));
    }

    /**
     * @return iterable<string, array{array<string, mixed>}>
     */
    public static function missingHeaderProvider(): iterable
    {
        yield 'no signature' => [['signature' => '']];
        yield 'no key id' => [['keyId' => '']];
        yield 'no request id' => [['requestId' => '']];
        yield 'no nonce' => [['nonce' => '']];
    }

    /**
     * @param array<string, mixed> $override
     */
    #[DataProvider('missingHeaderProvider')]
    public function testIncompleteAuthenticationMetadataIsRejected(array $override): void
    {
        self::assertFalse($this->verifier->verify('POST', self::PATH, $this->headers($override), '{}', self::NOW));
    }

    public function testStaleAndFutureTimestampsAreOutsideTheAcceptedWindow(): void
    {
        self::assertFalse($this->verifier->verify('POST', self::PATH, $this->headers(['timestamp' => self::NOW - 301]), '{}', self::NOW));
        self::assertFalse($this->verifier->verify('POST', self::PATH, $this->headers(['timestamp' => self::NOW + 301]), '{}', self::NOW));
        self::assertFalse($this->verifier->verify('POST', self::PATH, $this->headers(['timestamp' => 0]), '{}', self::NOW));
    }

    public function testUnknownOrEmptyKeyIdIsRejected(): void
    {
        self::assertFalse($this->verifier->verify('POST', self::PATH, $this->headers(['keyId' => 'vtone-2099x']), '{}', self::NOW));
    }

    public function testMalformedSignatureEncodingIsRejected(): void
    {
        self::assertFalse($this->verifier->verify('POST', self::PATH, $this->headers(['signature' => 'not-base64!!']), '{}', self::NOW));
        self::assertFalse($this->verifier->verify('POST', self::PATH, $this->headers(['signature' => base64_encode('short')]), '{}', self::NOW));
    }
}
