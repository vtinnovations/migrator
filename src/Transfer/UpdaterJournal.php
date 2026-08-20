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

namespace Vtinnovations\Migrator\Transfer;

use Vtinnovations\Migrator\Config\Paths;

/**
 * Bounded replay/idempotency journal for inbound server-initiated updater requests.
 *
 * Records the minimum needed to make an exact retry idempotent and a forged retry a security event:
 * the request id, a digest of the authenticated body, the applied version, and the processing time.
 * Retained beyond the replay window and pruned by count/age. No packet material, nonce, key or
 * signature is stored — only a one-way body digest.
 */
final class UpdaterJournal
{
    private const MAX_ENTRIES = 500;
    private const MAX_AGE = 2592000; // 30 days

    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * Prior outcome for a request id, or null if unseen.
     *
     * @return array{fingerprint:string, version:int, at:int}|null
     */
    public function find(string $requestId): ?array
    {
        $all = $this->load();
        $entry = $all[$requestId] ?? null;

        if (!\is_array($entry)) {
            return null;
        }

        return [
            'fingerprint' => (string) ($entry['fingerprint'] ?? ''),
            'version' => (int) ($entry['version'] ?? 0),
            'at' => (int) ($entry['at'] ?? 0),
        ];
    }

    /**
     * Record a processed request. $fingerprint is a one-way digest of the authenticated body.
     */
    public function record(string $requestId, string $fingerprint, int $version, int $now): void
    {
        $all = $this->load();
        $all[$requestId] = ['fingerprint' => $fingerprint, 'version' => $version, 'at' => $now];
        $this->save($this->prune($all, $now));
    }

    /** Stable one-way digest of the exact authenticated body bytes (never the body itself). */
    public function fingerprint(string $body): string
    {
        return hash('sha256', $body);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function load(): array
    {
        $file = $this->paths->updaterJournalFile();

        if (!is_file($file)) {
            return [];
        }

        $raw = file_get_contents($file);
        $data = false !== $raw ? json_decode($raw, true) : null;

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, array<string, mixed>> $all
     */
    private function save(array $all): void
    {
        $file = $this->paths->updaterJournalFile();
        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';
        $json = json_encode($all, JSON_UNESCAPED_SLASHES);

        if (false === $json || false === file_put_contents($tmp, $json, LOCK_EX) || !@rename($tmp, $file)) {
            @unlink($tmp);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $all
     *
     * @return array<string, array<string, mixed>>
     */
    private function prune(array $all, int $now): array
    {
        $all = array_filter($all, static fn (array $e): bool => ($now - (int) ($e['at'] ?? 0)) <= self::MAX_AGE);

        if (\count($all) > self::MAX_ENTRIES) {
            uasort($all, static fn (array $a, array $b): int => (int) ($b['at'] ?? 0) <=> (int) ($a['at'] ?? 0));
            $all = \array_slice($all, 0, self::MAX_ENTRIES, true);
        }

        return $all;
    }
}
