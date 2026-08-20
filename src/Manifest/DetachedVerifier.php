<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Manifest;

/**
 * Low-level detached-signature verification for the V-T.ONE 2026a profile (Ed25519 only).
 *
 * Callers pass the exact canonical message bytes, the signature as carried in the packet, and one
 * or more raw public keys resolved from {@see TrustRing}. Verification fails closed: a missing
 * sodium backend, an undecodable signature, or a wrong-length key returns false rather than
 * throwing, so a signed workflow degrades to "unverified" (and the caller preserves prior state).
 */
final class DetachedVerifier
{
    /** Ed25519 detached signatures are exactly 64 bytes. */
    private const ED25519_SIG_BYTES = 64;

    /**
     * Verify $message against $signature using exactly $rawKey.
     */
    public function verify(string $message, string $signature, string $rawKey): bool
    {
        if (!\function_exists('sodium_crypto_sign_verify_detached')) {
            return false;
        }

        $sig = $this->decodeSignature($signature);

        // Ed25519 public keys are 32 bytes; a wrong-length key would make sodium throw. TrustRing
        // already enforces this, but guard locally so any caller fails closed instead.
        if (null === $sig || 32 !== \strlen($rawKey)) {
            return false;
        }

        try {
            return sodium_crypto_sign_verify_detached($sig, $message, $rawKey);
        } catch (\SodiumException) {
            return false;
        }
    }

    /**
     * Verify $message against $signature using any one of $rawKeys (key_id => raw bytes). Returns
     * the matching key_id, or null if none verify. Used for the licence document, which names no
     * key and must be tried against every usable record-purpose key.
     *
     * @param array<string, string> $rawKeys
     */
    public function verifyAny(string $message, string $signature, array $rawKeys): ?string
    {
        foreach ($rawKeys as $keyId => $rawKey) {
            if ($this->verify($message, $signature, $rawKey)) {
                return $keyId;
            }
        }

        return null;
    }

    /**
     * Decode a signature carried as base64 (standard or URL-safe) or lowercase hex into raw bytes.
     * Returns null when the value does not decode to exactly a 64-byte Ed25519 signature.
     */
    private function decodeSignature(string $signature): ?string
    {
        $signature = trim($signature);

        if (1 === preg_match('/^[0-9a-f]{128}$/i', $signature)) {
            $raw = @hex2bin($signature);

            return false === $raw ? null : $raw;
        }

        $std = base64_decode(strtr($signature, '-_', '+/'), true);

        if (false !== $std && self::ED25519_SIG_BYTES === \strlen($std)) {
            return $std;
        }

        return null;
    }
}
