<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Tests\Transfer;

use PHPUnit\Framework\TestCase;
use Vtinnovations\Migrator\Manifest\CanonicalJson;
use Vtinnovations\Migrator\Manifest\DetachedVerifier;
use Vtinnovations\Migrator\Manifest\TrustRing;
use Vtinnovations\Migrator\Tests\SignedFixture;
use Vtinnovations\Migrator\Transfer\ExchangeException;
use Vtinnovations\Migrator\Transfer\ExchangeResponseReader;
use Vtinnovations\Migrator\Transfer\UpdaterRequestVerifier;

/**
 * The signed-packet pipeline, exercised through the SAME production code path a real packet takes.
 *
 * Positive verification requires an approved captured V-T.ONE packet, because only V-T.ONE holds
 * the private key. Those tests SKIP (never silently pass) until the fixture is provided — see
 * {@see SignedFixture}. The negative matrix below always runs.
 */
final class SignedPacketTest extends TestCase
{
    private ExchangeResponseReader $reader;

    protected function setUp(): void
    {
        $this->reader = new ExchangeResponseReader(new TrustRing(), new DetachedVerifier(), new CanonicalJson());
    }

    private function expectCategory(array $response, string $category): void
    {
        try {
            $this->reader->open($response, 1800000000);
            self::fail(sprintf('expected ExchangeException "%s"', $category));
        } catch (ExchangeException $e) {
            self::assertSame($category, $e->category());
        }
    }

    // --- negative matrix (always runs) --------------------------------------------------------

    public function testMissingPayloadOrEnvelopeIsMalformed(): void
    {
        $this->expectCategory([], 'malformed_response');
        $this->expectCategory(['license_payload_b64' => 'eyJhIjoxfQ=='], 'malformed_response');
        $this->expectCategory(['integrity' => ['signature_algorithm' => 'ed25519']], 'malformed_response');
    }

    public function testNonBase64PayloadIsRejected(): void
    {
        $this->expectCategory([
            'license_payload_b64' => '!!! not base64 !!!',
            'integrity' => ['signature_algorithm' => 'ed25519', 'key_id' => 'vtone-2026a'],
        ], 'bad_base64');
    }

    public function testAlgorithmIsAllowlistedBeforeAnyKeyLookup(): void
    {
        $this->expectCategory([
            'license_payload_b64' => base64_encode('{"schema_version":2}'),
            'integrity' => ['signature_algorithm' => 'hmac-sha256', 'key_id' => 'vtone-2026a'],
        ], 'unknown_algorithm');
    }

    public function testUnknownKeyIdIsNeverResolvedFromThePacketItself(): void
    {
        $this->expectCategory([
            'license_payload_b64' => base64_encode('{"schema_version":2}'),
            'integrity' => [
                'signature_algorithm' => 'ed25519',
                'key_id' => 'attacker-supplied',
                'public_key' => 'qllgm+66FUVBFJ3O68ICFG8b37dR+9jMfr1+4/pSygE=',
                'signature' => base64_encode(str_repeat("\0", 64)),
            ],
        ], 'unknown_signing_key');
    }

    public function testEnvelopeSignatureIsVerifiedBeforeItsMd5IsTrusted(): void
    {
        $bytes = '{"schema_version":2}';

        // The MD5 matches the bytes exactly, but the envelope is not authentic: recalculating the
        // digest over modified content must NOT make a package acceptable.
        $this->expectCategory([
            'license_payload_b64' => base64_encode($bytes),
            'integrity' => [
                'signature_algorithm' => 'ed25519',
                'key_id' => 'vtone-2026a',
                'license_md5' => md5($bytes),
                'signature' => base64_encode(str_repeat("\0", 64)),
            ],
        ], 'bad_envelope_signature');
    }

    // --- positive matrix (requires the approved captured packet) -------------------------------

    public function testApprovedActivationResponseVerifiesThroughProductionCode(): void
    {
        if (!SignedFixture::has(SignedFixture::ACTIVATION)) {
            self::markTestSkipped(SignedFixture::missingMessage(SignedFixture::ACTIVATION));
        }

        $response = SignedFixture::load(SignedFixture::ACTIVATION);
        $record = $this->reader->open($response, (int) ($response['integrity']['generated_at'] ?? time()));

        self::assertSame(2, $record->schemaVersion());
        self::assertSame('migrator', $record->slug());
        self::assertNotSame('', $record->key());
        self::assertSame(
            $record->rawBytes,
            base64_decode((string) $response['license_payload_b64'], true),
            'the stored bytes must be the exact decoded payload, never a reserialized form',
        );
        self::assertSame(md5($record->rawBytes), strtolower($record->envelopeMd5()));
        self::assertContains($record->domain(), $record->domains());
        self::assertGreaterThan(0, $record->maxDomains());
    }

    public function testApprovedActivationResponseFailsAfterASingleByteMutation(): void
    {
        if (!SignedFixture::has(SignedFixture::ACTIVATION)) {
            self::markTestSkipped(SignedFixture::missingMessage(SignedFixture::ACTIVATION));
        }

        $response = SignedFixture::load(SignedFixture::ACTIVATION);
        $bytes = (string) base64_decode((string) $response['license_payload_b64'], true);
        $response['license_payload_b64'] = base64_encode($bytes.' ');

        $this->expectCategory($response, 'md5_mismatch');
    }

    public function testApprovedUpdaterRequestVerifiesAndIsBoundToMethodAndPath(): void
    {
        if (!SignedFixture::has(SignedFixture::UPDATER_REQUEST)) {
            self::markTestSkipped(SignedFixture::missingMessage(SignedFixture::UPDATER_REQUEST));
        }

        $fixture = SignedFixture::load(SignedFixture::UPDATER_REQUEST);
        $headers = (array) $fixture['headers'];
        $body = (string) $fixture['body'];

        $mapped = [
            'requestId' => (string) $headers['X-VT-Request-ID'],
            'timestamp' => (int) $headers['X-VT-Timestamp'],
            'nonce' => (string) $headers['X-VT-Nonce'],
            'keyId' => (string) $headers['X-VT-Key-ID'],
            'signature' => (string) $headers['X-VT-Signature'],
        ];

        $verifier = new UpdaterRequestVerifier(new TrustRing(), new DetachedVerifier());
        $now = $mapped['timestamp'];
        $path = '/rest/api/v1/migrator-license-updater';

        self::assertTrue($verifier->verify('POST', $path, $mapped, $body, $now));
        self::assertFalse($verifier->verify('PUT', $path, $mapped, $body, $now), 'method is part of the signed input');
        self::assertFalse($verifier->verify('POST', $path.'x', $mapped, $body, $now), 'path is part of the signed input');
        self::assertFalse($verifier->verify('POST', $path, $mapped, $body.' ', $now), 'the raw body hash is signed');
        self::assertFalse($verifier->verify('POST', $path, $mapped, $body, $now + 301), 'the window is enforced');
    }
}
