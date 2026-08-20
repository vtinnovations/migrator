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

namespace Vtinnovations\Migrator\Archive;

use Vtinnovations\Migrator\Config\ConfigStore;

/**
 * Enumerates the project tree (honouring the exclude list) into a flat manifest of
 * relative paths + sizes. The actual streaming into chunked tar.gz files is driven by
 * ArchiveFilesStep, which spends the time budget and rolls chunks at archive_chunk_bytes.
 */
final class FileArchiver
{
    public function __construct(
        private readonly ConfigStore $config,
        private readonly string $projectDir,
    ) {
    }

    public function projectDir(): string
    {
        return rtrim($this->projectDir, '/\\');
    }

    /**
     * @param bool $includeVendor when true, ships vendor/ too (the composer-fix path) so the
     *                            destination needs no composer install
     *
     * @return list<array{path:string, size:int}>
     */
    public function enumerate(bool $includeVendor = false): array
    {
        $root = $this->projectDir();
        $excludes = array_values(array_filter((array) $this->config->get('excludes', [])));

        if ($includeVendor) {
            // Drop the top-level vendor exclude only (keep e.g. assets/vendor).
            $excludes = array_values(array_filter($excludes, static fn (string $e): bool => 'vendor' !== $e));
        }

        $files = [];

        $it = new \RecursiveIteratorIterator(
            new \RecursiveCallbackFilterIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS),
                function (\SplFileInfo $current) use ($root, $excludes): bool {
                    $rel = $this->relative($root, $current->getPathname());

                    foreach ($excludes as $ex) {
                        if (str_starts_with($rel, $ex.'/') || $rel === $ex) {
                            return false;
                        }
                    }

                    return true;
                }
            ),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($it as $file) {
            if (!$file instanceof \SplFileInfo || !$file->isFile()) {
                continue;
            }

            $files[] = [
                'path' => $this->relative($root, $file->getPathname()),
                'size' => max(0, $file->getSize()),
            ];
        }

        return $files;
    }

    public function chunkBytes(): int
    {
        return (int) $this->config->get('archive_chunk_bytes', 52428800);
    }

    private function relative(string $root, string $path): string
    {
        return str_replace('\\', '/', ltrim(substr($path, \strlen($root)), '/\\'));
    }
}
