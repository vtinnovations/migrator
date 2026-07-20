<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Config;

/**
 * User-tunable settings persisted to var/migrator/config.json, merged over defaults.
 */
final class ConfigStore
{
    private const DEFAULTS = [
        // Paths excluded from the file archive (prefix match against the project-relative path).
        'excludes' => [
            'vendor',
            'var/cache',
            'var/log',
            'var/migrator',
            'node_modules',
            '.git',
            '.github',
            'assets/vendor',
        ],
        // Target size of each files/files.NNN.tar.gz chunk (bytes).
        'archive_chunk_bytes' => 52428800, // 50 MiB
        // Starting size of each Mode B push chunk (bytes). The push step auto-shrinks (halving to
        // a 64 KiB floor) when the destination answers 413, so this is only the opening bid —
        // lower it to save shrink round-trips on a host with a known-tight body limit.
        'push_chunk_bytes' => 4194304, // 4 MiB
        // Rows per database INSERT chunk file.
        'db_rows_per_chunk' => 2000,
        // Cap a single INSERT statement at this many bytes (stay under max_allowed_packet).
        'db_max_insert_bytes' => 1048576, // 1 MiB
        // How many finished backups to keep before pruning oldest.
        'retention_backups' => 3,
        // Max bytes per download volume for Mode A split download (0 = never split, one file).
        // Keep under the destination's upload_max_filesize/post_max_size so each part uploads.
        'download_volume_bytes' => 83886080, // 80 MiB
        // 'keep' = preserve destination MAILER_DSN on import; 'carry' = take the source's.
        'mailer_policy' => 'keep',
        // Keep destination admin accounts during import (do not let source overwrite them).
        'preserve_destination_admins' => true,
        // Hosts allowed to push to this install in Mode B (empty = none until paired).
        'allowed_peers' => [],
    ];

    /** @var array<string, mixed>|null */
    private ?array $cache = null;

    public function __construct(private readonly Paths $paths)
    {
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $cfg = $this->all();

        return $cfg[$key] ?? $default;
    }

    /**
     * @return array<string, mixed>
     */
    public function all(): array
    {
        if (null !== $this->cache) {
            return $this->cache;
        }

        $file = $this->paths->configFile();
        $stored = [];

        if (is_file($file)) {
            $raw = file_get_contents($file);
            $decoded = false !== $raw ? json_decode($raw, true) : null;

            if (\is_array($decoded)) {
                $stored = $decoded;
            }
        }

        return $this->cache = array_replace(self::DEFAULTS, $stored);
    }

    public function set(string $key, mixed $value): void
    {
        $cfg = $this->all();
        $cfg[$key] = $value;
        $this->saveAll($cfg);
    }

    /**
     * @param array<string, mixed> $values
     */
    public function saveAll(array $values): void
    {
        $merged = array_replace(self::DEFAULTS, $values);
        $json = json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $json) {
            throw new \RuntimeException('Failed to encode config: '.json_last_error_msg());
        }

        $file = $this->paths->configFile();
        $tmp = $file.'.tmp';

        if (false === file_put_contents($tmp, $json, LOCK_EX) || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Failed to persist migrator config.');
        }

        $this->cache = $merged;
    }
}
