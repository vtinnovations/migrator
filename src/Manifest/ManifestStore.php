<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Manifest;

use Vtinnovations\Migrator\Config\Paths;

/**
 * Persists manifest.json inside a job's backup dir. Atomic writes so a partial flush
 * during a time-budget cut never leaves a truncated manifest.
 */
final class ManifestStore
{
    public function __construct(private readonly Paths $paths)
    {
    }

    public function path(string $jobId): string
    {
        return $this->paths->backupDir($jobId).'/manifest.json';
    }

    public function load(string $jobId): ?Manifest
    {
        return $this->loadPath($this->path($jobId));
    }

    /**
     * Load a manifest from an arbitrary path (e.g. an unpacked package in the staging dir).
     */
    public function loadPath(string $file): ?Manifest
    {
        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);
        $data = false !== $raw ? json_decode($raw, true) : null;

        return \is_array($data) ? Manifest::fromArray($data) : null;
    }

    public function save(string $jobId, Manifest $manifest): void
    {
        $file = $this->path($jobId);
        $tmp = $file.'.tmp';

        $json = json_encode($manifest->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $json) {
            throw new \RuntimeException('Failed to encode manifest: '.json_last_error_msg());
        }

        if (false === file_put_contents($tmp, $json, LOCK_EX) || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Failed to persist manifest "%s".', $file));
        }
    }
}
