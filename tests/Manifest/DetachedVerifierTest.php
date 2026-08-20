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

namespace Vtinnovations\Migrator\Tests\Manifest;

use PHPUnit\Framework\TestCase;
use Vtinnovations\Migrator\Manifest\DetachedVerifier;

/**
 * Exercises the Ed25519 verification primitive with a locally generated throwaway keypair. This
 * proves the verifier itself accepts a genuine signature and rejects everything else; it does NOT
 * fabricate a V-T.ONE vector — the pinned-ring path is covered by the approved fixture tests.
 */
final class DetachedVerifierTest extends TestCase
{
    private DetachedVerifier $verifier;

    /** @var array{public:string, secret:string} */
    private array $keys;

    protected function setUp(): void
    {
        if (!\function_exists('sodium_crypto_sign_keypair')) {
            self::markTestSkipped('ext-sodium is required for Ed25519 verification.');
        }

        $pair = sodium_crypto_sign_keypair();
        $this->keys = [
            'public' => sodium_crypto_sign_publickey($pair),
            'secret' => sodium_crypto_sign_secretkey($pair),
        ];
        $this->verifier = new DetachedVerifier();
    }

    public function testAcceptsBase64AndHexEncodedSignatures(): void
    {
        $message = '{"license_version":7}';
        $raw = sodium_crypto_sign_detached($message, $this->keys['secret']);

        self::assertTrue($this->verifier->verify($message, base64_encode($raw), $this->keys['public']));
        self::assertTrue($this->verifier->verify($message, bin2hex($raw), $this->keys['public']));
        self::assertTrue($this->verifier->verify(
            $message,
            rtrim(strtr(base64_encode($raw), '+/', '-_'), '='),
            $this->keys['public'],
        ));
    }

    public function testRejectsAnyMutationOfTheSignedBytes(): void
    {
        $message = '{"license_version":7}';
        $signature = base64_encode(sodium_crypto_sign_detached($message, $this->keys['secret']));

        self::assertFalse($this->verifier->verify('{"license_version":8}', $signature, $this->keys['public']));
        self::assertFalse($this->verifier->verify($message.' ', $signature, $this->keys['public']));
    }

    public function testRejectsWrongKeyMalformedSignatureAndWrongKeyLength(): void
    {
        $message = 'payload';
        $signature = base64_encode(sodium_crypto_sign_detached($message, $this->keys['secret']));
        $other = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());

        self::assertFalse($this->verifier->verify($message, $signature, $other));
        self::assertFalse($this->verifier->verify($message, 'not-a-signature', $this->keys['public']));
        self::assertFalse($this->verifier->verify($message, '', $this->keys['public']));
        self::assertFalse($this->verifier->verify($message, $signature, 'short-key'));
    }

    public function testVerifyAnyReturnsTheMatchingKeyIdOrNull(): void
    {
        $message = 'document';
        $signature = base64_encode(sodium_crypto_sign_detached($message, $this->keys['secret']));
        $other = sodium_crypto_sign_publickey(sodium_crypto_sign_keypair());

        self::assertSame('b', $this->verifier->verifyAny($message, $signature, [
            'a' => $other,
            'b' => $this->keys['public'],
        ]));
        self::assertNull($this->verifier->verifyAny($message, $signature, ['a' => $other]));
        self::assertNull($this->verifier->verifyAny($message, $signature, []));
    }
}
