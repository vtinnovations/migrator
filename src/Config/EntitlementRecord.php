<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Config;

/**
 * Immutable view over an authenticated licence document plus the integrity envelope it arrived in.
 *
 * The record holds the EXACT decoded bytes (never a re-serialized form) so the MD5 tripwire and
 * the document signature always verify against the same bytes the signer produced. Typed accessors
 * expose the schema-version-2 fields; they perform no policy decisions — model/domain evaluation
 * lives in the evaluation layer, so this stays a pure value object.
 */
final class EntitlementRecord
{
    /**
     * @param array<string, mixed> $document decoded licence JSON (exact structure)
     * @param array<string, mixed> $envelope decoded integrity envelope
     */
    private function __construct(
        public readonly string $rawBytes,
        private readonly array $document,
        private readonly array $envelope,
    ) {
    }

    /**
     * Build from exact licence bytes and the envelope array. Throws when the bytes are not a JSON
     * object — callers verify signatures/MD5 before constructing.
     *
     * @param array<string, mixed> $envelope
     */
    public static function fromBytes(string $rawBytes, array $envelope): self
    {
        $document = json_decode($rawBytes, true);

        if (!\is_array($document)) {
            throw new \RuntimeException('Licence bytes are not a JSON object.');
        }

        return new self($rawBytes, $document, $envelope);
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        return $this->document;
    }

    /** @return array<string, mixed> */
    public function envelope(): array
    {
        return $this->envelope;
    }

    public function schemaVersion(): int
    {
        return (int) ($this->document['schema_version'] ?? 0);
    }

    public function project(): string
    {
        return (string) ($this->document['project'] ?? '');
    }

    public function slug(): string
    {
        return (string) ($this->document['project_slug'] ?? '');
    }

    public function key(): string
    {
        return (string) ($this->document['license_key'] ?? '');
    }

    /**
     * The key with only its two ends legible, for the administrator surface.
     *
     * Masking happens here rather than at the render site so the status block
     * never has to hold the full key to show which licence is installed — the
     * secrecy audit forbids it reading key() at all. A key too short to keep
     * both ends recognisable is masked whole: half of a short key is not a
     * hint, it is the key.
     */
    public function maskedKey(): string
    {
        $key = trim($this->key());
        $length = mb_strlen($key);

        if (0 === $length) {
            return '';
        }

        if ($length <= 8) {
            return str_repeat('•', $length);
        }

        return mb_substr($key, 0, 4).str_repeat('•', max(4, $length - 8)).mb_substr($key, -4);
    }

    public function domain(): string
    {
        return (string) ($this->document['license_domain'] ?? '');
    }

    /** @return list<string> */
    public function domains(): array
    {
        $domains = $this->document['license_domains'] ?? [];

        if (!\is_array($domains)) {
            return [];
        }

        return array_values(array_map('strval', $domains));
    }

    public function maxDomains(): int
    {
        return (int) ($this->document['license_max_domains'] ?? 0);
    }

    public function package(): string
    {
        return (string) ($this->document['license_package'] ?? '');
    }

    /** @return list<string> */
    public function features(): array
    {
        $features = $this->document['license_features'] ?? [];

        if (!\is_array($features)) {
            return [];
        }

        return array_values(array_map('strval', $features));
    }

    public function version(): int
    {
        return (int) ($this->document['license_version'] ?? 0);
    }

    public function issuedAt(): ?int
    {
        return $this->intOrNull('license_issued_at');
    }

    public function startsAt(): ?int
    {
        return $this->intOrNull('license_starts_at');
    }

    public function expiresAt(): ?int
    {
        return $this->intOrNull('license_expires_at');
    }

    public function lifetime(): bool
    {
        return true === ($this->document['license_lifetime'] ?? false);
    }

    public function verifiedAt(): ?int
    {
        return $this->intOrNull('license_verified_at');
    }

    public function freeAvailable(): bool
    {
        return true === ($this->document['free_available'] ?? false);
    }

    public function signature(): string
    {
        return (string) ($this->document['signature'] ?? '');
    }

    public function validationStatus(): string
    {
        return (string) ($this->document['validation_status'] ?? '');
    }

    public function envelopeKeyId(): string
    {
        return (string) ($this->envelope['key_id'] ?? '');
    }

    public function envelopeMd5(): string
    {
        return (string) ($this->envelope['license_md5'] ?? '');
    }

    private function intOrNull(string $field): ?int
    {
        $value = $this->document[$field] ?? null;

        return null === $value ? null : (int) $value;
    }
}
