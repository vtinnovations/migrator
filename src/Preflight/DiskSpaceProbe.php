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

namespace Vtinnovations\Migrator\Preflight;

use Doctrine\DBAL\Connection;
use Vtinnovations\Migrator\Config\ConfigStore;

/**
 * Estimates the disk space an export needs (DB size + file tree minus excludes) times a
 * safety factor, and compares against free space so PreflightStep can fail fast instead
 * of dying mid-archive with a half-written backup.
 */
final class DiskSpaceProbe
{
    private const SAFETY_FACTOR = 2.0;

    public function __construct(
        private readonly Connection $connection,
        private readonly ConfigStore $config,
        private readonly string $projectDir,
    ) {
    }

    /**
     * @return array{databaseBytes:int, filesBytes:int, requiredBytes:int, freeBytes:int, sufficient:bool}
     */
    public function probe(): array
    {
        $dbBytes = $this->databaseBytes();
        $fileBytes = $this->filesBytes();
        $required = (int) (($dbBytes + $fileBytes) * self::SAFETY_FACTOR);

        $free = @disk_free_space($this->projectDir);
        $free = false === $free ? 0 : (int) $free;

        return [
            'databaseBytes' => $dbBytes,
            'filesBytes' => $fileBytes,
            'requiredBytes' => $required,
            'freeBytes' => $free,
            'sufficient' => $free >= $required,
        ];
    }

    private function databaseBytes(): int
    {
        try {
            $value = $this->connection->fetchOne(
                'SELECT SUM(data_length + index_length) FROM information_schema.TABLES WHERE table_schema = DATABASE()'
            );

            return null === $value ? 0 : (int) $value;
        } catch (\Throwable) {
            // Non-MySQL (e.g. SQLite test harness) — skip the estimate.
            return 0;
        }
    }

    private function filesBytes(): int
    {
        $excludes = (array) $this->config->get('excludes', []);
        $root = rtrim($this->projectDir, '/\\');
        $total = 0;

        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                function (\SplFileInfo $current) use ($root, $excludes): bool {
                    $rel = $this->relative($root, $current->getPathname());

                    foreach ($excludes as $ex) {
                        if ('' !== $ex && (str_starts_with($rel, $ex.'/') || $rel === $ex)) {
                            return false;
                        }
                    }

                    return true;
                }
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            if ($file instanceof \SplFileInfo && $file->isFile()) {
                $total += max(0, $file->getSize());
            }
        }

        return $total;
    }

    private function relative(string $root, string $path): string
    {
        $rel = ltrim(substr($path, \strlen($root)), '/\\');

        return str_replace('\\', '/', $rel);
    }
}
