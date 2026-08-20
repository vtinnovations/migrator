<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Manifest;

/**
 * Pinned public verification material for the shared V-T.ONE 2026a signing policy.
 *
 * This is a trust anchor, not configuration: the ring is fixed in distributed code and never
 * sourced from a request, a response, DNS, or an environment variable. It contains only PUBLIC
 * keys — private signing material lives exclusively on V-T.ONE infrastructure.
 *
 * A production ring must be non-empty and every entry must decode to a structurally valid key of
 * its declared algorithm; empty/placeholder/zero-length material is rejected so a build can never
 * ship a ring that cannot verify a real response. Key lookup is by `key_id`, constrained to an
 * Ed25519-only algorithm allowlist for this profile. A `key_id` is only a selector — it is never
 * treated as a key, and a rotation key is never accepted from the same response it would verify.
 *
 * Release hardening MAY split/transform the encoded key bytes below and reconstruct them at load
 * time; the readiness check re-derives the fingerprint to prove the exact bytes survived.
 */
final class TrustRing
{
    private const ALGO_ED25519 = 'ed25519';

    /** Ed25519 raw public keys are exactly 32 bytes. */
    private const ED25519_PUBLIC_BYTES = 32;

    /**
     * Pinned keys, indexed by key_id. `retiredAt` null means no retirement declared. `purposes`
     * gates which signature domain a key may verify (record = licence document, envelope =
     * integrity envelope, request = updater HTTP request).
     *
     * @var array<string, array{algo:string, keyB64:string, purposes:list<string>, activatedAt:int, retiredAt:int|null, fingerprintPrefix:string}>
     */
    private const KEYS = [
        'vtone-2026a' => [
            'algo' => self::ALGO_ED25519,
            'keyB64' => 'qllgm+66FUVBFJ3O68ICFG8b37dR+9jMfr1+4/pSygE=',
            'purposes' => ['record', 'envelope', 'request'],
            'activatedAt' => 0,
            'retiredAt' => null,
            'fingerprintPrefix' => 'edcd614e70c59ce0',
        ],
        // The server publishes key_id as either the human label above OR, when no label override is
        // set, the first 16 hex chars of the SHA-256 public-key fingerprint. Same key bytes, same
        // purposes — pinned under both selectors so verification succeeds whichever id the envelope
        // carries. (SigningKeyStore::getKeyId derives exactly this fingerprint prefix.)
        'edcd614e70c59ce0' => [
            'algo' => self::ALGO_ED25519,
            'keyB64' => 'qllgm+66FUVBFJ3O68ICFG8b37dR+9jMfr1+4/pSygE=',
            'purposes' => ['record', 'envelope', 'request'],
            'activatedAt' => 0,
            'retiredAt' => null,
            'fingerprintPrefix' => 'edcd614e70c59ce0',
        ],
    ];

    public const PURPOSE_RECORD = 'record';
    public const PURPOSE_ENVELOPE = 'envelope';
    public const PURPOSE_REQUEST = 'request';

    /**
     * True only if at least one structurally valid pinned key exists. A false result must block a
     * signed workflow (fail closed) and, at build time, block producing a distributable artefact.
     */
    public function isReady(): bool
    {
        foreach (array_keys(self::KEYS) as $keyId) {
            if (null !== $this->rawKey($keyId, null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Raw decoded public-key bytes for an exact key_id, or null when the id is unknown, retired
     * outside its window at $now, structurally invalid, or of an unexpected algorithm. When
     * $purpose is given the key must also be approved for that signature domain.
     */
    public function rawKey(string $keyId, ?string $purpose, ?int $now = null): ?string
    {
        $entry = self::KEYS[$keyId] ?? null;

        if (null === $entry || self::ALGO_ED25519 !== $entry['algo']) {
            return null;
        }

        if (null !== $purpose && !\in_array($purpose, $entry['purposes'], true)) {
            return null;
        }

        if (null !== $now) {
            if ($now < $entry['activatedAt']) {
                return null;
            }

            if (null !== $entry['retiredAt'] && $now > $entry['retiredAt']) {
                return null;
            }
        }

        $raw = base64_decode($entry['keyB64'], true);

        if (false === $raw || self::ED25519_PUBLIC_BYTES !== \strlen($raw)) {
            return null;
        }

        // Re-derive and compare the declared fingerprint prefix so a corrupted/placeholder key is
        // rejected rather than silently used.
        if (!hash_equals($entry['fingerprintPrefix'], substr(hash('sha256', $raw), 0, \strlen($entry['fingerprintPrefix'])))) {
            return null;
        }

        return $raw;
    }

    /**
     * Every currently usable raw key approved for a purpose. The licence document names no key, so
     * its detached signature is tried against each usable record-purpose key until one verifies.
     *
     * @return array<string, string> key_id => raw key bytes
     */
    public function usableKeys(string $purpose, ?int $now = null): array
    {
        $out = [];

        foreach (array_keys(self::KEYS) as $keyId) {
            $raw = $this->rawKey($keyId, $purpose, $now);

            if (null !== $raw) {
                $out[$keyId] = $raw;
            }
        }

        return $out;
    }
}
