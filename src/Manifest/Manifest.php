<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Manifest;

/**
 * The .tcmig manifest: a growing description of an export (environment, database tables,
 * file chunks, secrets, URL-replacement candidates).
 *
 * For a stable HMAC signature the payload is serialised as canonical JSON — recursively
 * key-sorted — so reordering keys can never change the signed bytes.
 */
final class Manifest
{
    public const FORMAT_VERSION = 1;

    /** @var array<string, mixed> */
    private array $data;

    public function __construct(string $jobId)
    {
        $this->data = [
            'format' => self::FORMAT_VERSION,
            'jobId' => $jobId,
            'createdAt' => gmdate('c'),
            'environment' => [],
            'database' => ['tables' => []],
            'files' => ['chunks' => []],
            'secrets' => [],
            'urlReplacements' => ['candidates' => [], 'hasEmptyDns' => false],
            'signature' => null,
        ];
    }

    public function set(string $section, mixed $value): void
    {
        $this->data[$section] = $value;
    }

    public function get(string $section, mixed $default = null): mixed
    {
        return $this->data[$section] ?? $default;
    }

    public function setTable(string $table, array $info): void
    {
        $this->data['database']['tables'][$table] = $info;
    }

    public function addFileChunk(array $chunk): void
    {
        $this->data['files']['chunks'][] = $chunk;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    public static function fromArray(array $data): self
    {
        $m = new self((string) ($data['jobId'] ?? ''));
        $m->data = $data;

        return $m;
    }

    /**
     * Canonical JSON (recursively key-sorted) of everything except the signature field.
     */
    public function canonicalPayload(): string
    {
        $data = $this->data;
        unset($data['signature']);
        self::ksortRecursive($data);

        $json = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $json) {
            throw new \RuntimeException('Failed to encode manifest: '.json_last_error_msg());
        }

        return $json;
    }

    public function sign(callable $signer): void
    {
        $this->data['signature'] = $signer($this->canonicalPayload());
    }

    /**
     * @param callable(string $payload, string $signature): bool $verifier
     */
    public function verify(callable $verifier): bool
    {
        $sig = $this->data['signature'] ?? null;

        if (!\is_string($sig) || '' === $sig) {
            return false;
        }

        return $verifier($this->canonicalPayload(), $sig);
    }

    private static function ksortRecursive(array &$arr): void
    {
        ksort($arr);

        foreach ($arr as &$v) {
            if (\is_array($v)) {
                self::ksortRecursive($v);
            }
        }
        unset($v);
    }
}
