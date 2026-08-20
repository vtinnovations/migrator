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

namespace Vtinnovations\Migrator\Config;

/**
 * Atomic persistence for the authoritative entitlement pair (exact licence bytes + signed
 * integrity envelope + matched domain), in a private var/ location that never comes from a request.
 *
 * All mutation runs under an exclusive lock. A write validates the candidate, stages it to a
 * same-filesystem temp file, backs up the current valid state, atomically renames into place, then
 * re-reads and revalidates — rolling back to the backup if any post-activation check fails. Because
 * the licence bytes and their envelope live in ONE file, a crash can never leave them mismatched.
 *
 * A transport/network failure must not erase a previously valid licence: callers only invoke
 * {@see self::save()} after full cryptographic verification of a complete replacement package.
 */
final class EntitlementStore
{
    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * Run $fn while holding the entitlement lock. Returns $fn's result, or null if the lock is held.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T|null
     */
    public function withLock(callable $fn): mixed
    {
        $handle = fopen($this->paths->entitlementLockFile(), 'c');

        if (false === $handle) {
            throw new \RuntimeException('Could not open entitlement lock.');
        }

        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) {
                return null;
            }

            try {
                return $fn();
            } finally {
                flock($handle, LOCK_UN);
            }
        } finally {
            fclose($handle);
        }
    }

    /**
     * Persist exact licence bytes + envelope + matched domain as one atomic transaction. Must be
     * called under {@see self::withLock()} by the caller (activation/refresh/push handler).
     *
     * @param array<string, mixed> $envelope
     */
    public function save(string $rawBytes, array $envelope, string $matchedDomain): void
    {
        $file = $this->paths->entitlementFile();
        $payload = [
            'payload_b64' => base64_encode($rawBytes),
            'integrity' => $envelope,
            'matched_domain' => $matchedDomain,
            'stored_at' => gmdate('c'),
        ];

        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $json) {
            throw new \RuntimeException('Could not encode entitlement state.');
        }

        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';

        if (false === file_put_contents($tmp, $json, LOCK_EX)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not stage entitlement state.');
        }

        $this->fsync($tmp);

        // Revalidate the staged bytes before activating them.
        if (!$this->stagedMatches($tmp, $rawBytes)) {
            @unlink($tmp);
            throw new \RuntimeException('Staged entitlement failed pre-activation revalidation.');
        }

        // Back up the current valid state for rollback.
        if (is_file($file)) {
            @copy($file, $this->paths->entitlementBackupFile());
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not activate entitlement state.');
        }

        // Post-activation revalidation; roll back on any mismatch.
        $active = $this->loadRaw();

        if (null === $active || !hash_equals(md5($rawBytes), md5($active['bytes']))) {
            $this->rollback();
            throw new \RuntimeException('Post-activation entitlement check failed; rolled back.');
        }
    }

    /**
     * Read the stored pair, or null when absent/unreadable. Cryptographic re-verification of the
     * bytes against the envelope is the evaluation layer's job, not this store's.
     *
     * @return array{bytes:string, envelope:array<string,mixed>, matchedDomain:string}|null
     */
    public function loadRaw(): ?array
    {
        $file = $this->paths->entitlementFile();

        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        $data = false !== $raw ? json_decode($raw, true) : null;

        if (!\is_array($data) || !isset($data['payload_b64'], $data['integrity'])) {
            return null;
        }

        $bytes = base64_decode((string) $data['payload_b64'], true);

        if (false === $bytes) {
            return null;
        }

        return [
            'bytes' => $bytes,
            'envelope' => (array) $data['integrity'],
            'matchedDomain' => (string) ($data['matched_domain'] ?? ''),
        ];
    }

    public function exists(): bool
    {
        return is_file($this->paths->entitlementFile());
    }

    /**
     * Remove the authoritative state. Must be called under {@see self::withLock()}.
     */
    public function remove(): void
    {
        @unlink($this->paths->entitlementFile());
        @unlink($this->paths->entitlementBackupFile());
    }

    private function rollback(): void
    {
        $backup = $this->paths->entitlementBackupFile();

        if (is_file($backup)) {
            @copy($backup, $this->paths->entitlementFile());
        } else {
            @unlink($this->paths->entitlementFile());
        }
    }

    private function stagedMatches(string $tmp, string $rawBytes): bool
    {
        $raw = file_get_contents($tmp);
        $data = false !== $raw ? json_decode($raw, true) : null;

        if (!\is_array($data)) {
            return false;
        }

        $bytes = base64_decode((string) ($data['payload_b64'] ?? ''), true);

        return false !== $bytes && hash_equals(md5($rawBytes), md5($bytes));
    }

    private function fsync(string $file): void
    {
        $handle = @fopen($file, 'rb');

        if (false === $handle) {
            return;
        }

        try {
            if (\function_exists('fsync')) {
                @fsync($handle);
            }
        } finally {
            fclose($handle);
        }
    }
}
