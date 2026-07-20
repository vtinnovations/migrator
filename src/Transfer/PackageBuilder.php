<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Transfer;

use Vtinnovations\Migrator\Archive\TarGzWriter;
use Vtinnovations\Migrator\Config\Paths;

/**
 * Materialises a single .tcmig from a finished export's backup dir — a tar.gz whose members
 * are exactly what UnpackPackageStep expects (manifest.json, db/, files/, secrets/,
 * checksums.json). Used by Mode B (push) and by the Mode A download controller as a fallback
 * when streaming live is not desired.
 *
 * Members are already-compressed chunks, so the outer gzip is near-passthrough; the point is
 * a single self-describing file the destination can verify and unpack.
 */
final class PackageBuilder
{
    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * Build {jobId}.tcmig under the scratch dir from the job's backup dir. Returns its path.
     */
    public function build(string $jobId): string
    {
        $backupDir = rtrim($this->paths->backupDir($jobId), '/\\');
        $out = $this->paths->scratch().'/'.$jobId.'.tcmig';

        // A finished backup dir is immutable, so a previously built package is reusable. Caching
        // matters for the Mode A split download: each volume request must slice the SAME file,
        // and rebuilding a multi-GB package on every part request would be wasteful.
        if (is_file($out) && filesize($out) > 0) {
            return $out;
        }

        $writer = new TarGzWriter($out);

        try {
            foreach ($this->enumerate($backupDir) as $rel) {
                $writer->addFile($backupDir.'/'.$rel, $rel);
            }
        } finally {
            $writer->close();
        }

        return $out;
    }

    /**
     * Project-relative paths of every file in the backup dir, excluding transient sidecars.
     *
     * @return list<string>
     */
    private function enumerate(string $backupDir): array
    {
        if (!is_dir($backupDir)) {
            throw new \RuntimeException('Backup dir missing: '.$backupDir);
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($backupDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::LEAVES_ONLY
        );

        $out = [];
        $prefixLen = \strlen($backupDir) + 1;

        foreach ($items as $item) {
            if (!$item->isFile()) {
                continue;
            }

            $rel = str_replace('\\', '/', substr($item->getPathname(), $prefixLen));

            // files-index.json is a transient enumeration sidecar — never part of the package.
            if ('files-index.json' === $rel || str_ends_with($rel, '.tmp')) {
                continue;
            }

            $out[] = $rel;
        }

        sort($out);

        return $out;
    }
}
