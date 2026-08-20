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

/**
 * Streaming tar.gz extractor — counterpart to TarGzWriter. Reads ustar headers and the GNU
 * '././@LongLink' long-name extension this bundle emits, writing each entry out in blocks
 * so memory stays flat. Guards against path traversal (no absolute paths, no "..").
 */
final class TarGzReader
{
    private const BLOCK = 512;
    private const COPY_CHUNK = 1048576;

    /**
     * Extract $archive into $destDir. Returns the list of relative paths written.
     *
     * @return list<string>
     */
    public function extract(string $archive, string $destDir): array
    {
        $gz = gzopen($archive, 'rb');

        if (false === $gz) {
            throw new \RuntimeException(sprintf('Could not open archive "%s".', $archive));
        }

        $destDir = rtrim($destDir, '/\\');
        $written = [];
        $longName = null;

        try {
            while (true) {
                $header = $this->readBlock($gz);

                if (null === $header || '' === trim($header, "\0")) {
                    break; // end of archive (zero block) or EOF
                }

                $meta = $this->parseHeader($header);

                if ('L' === $meta['typeflag']) {
                    $longName = rtrim($this->readData($gz, $meta['size']), "\0");
                    continue;
                }

                $name = $longName ?? $meta['name'];
                $longName = null;

                $rel = $this->sanitise($name);

                if ('' === $rel) {
                    $this->skip($gz, $meta['size']);
                    continue;
                }

                // Directory entry.
                if ('5' === $meta['typeflag'] || str_ends_with($name, '/')) {
                    @mkdir($destDir.'/'.$rel, 0775, true);
                    continue;
                }

                $this->writeEntry($gz, $destDir.'/'.$rel, $meta['size']);
                $written[] = $rel;
            }
        } finally {
            gzclose($gz);
        }

        return $written;
    }

    private function writeEntry($gz, string $absPath, int $size): void
    {
        $dir = \dirname($absPath);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create dir "%s".', $dir));
        }

        $out = fopen($absPath, 'wb');

        if (false === $out) {
            throw new \RuntimeException(sprintf('Could not open "%s" for writing.', $absPath));
        }

        $remaining = $size;

        try {
            while ($remaining > 0) {
                $read = min(self::COPY_CHUNK, $remaining);
                $buf = gzread($gz, $read);

                if (false === $buf || '' === $buf) {
                    throw new \RuntimeException('Unexpected EOF while extracting '.$absPath);
                }

                fwrite($out, $buf);
                $remaining -= \strlen($buf);
            }
        } finally {
            fclose($out);
        }

        $this->skipPadding($gz, $size);
    }

    private function readBlock($gz): ?string
    {
        $data = gzread($gz, self::BLOCK);

        if (false === $data || '' === $data) {
            return null;
        }

        // Pad a short final read to a full block.
        if (\strlen($data) < self::BLOCK) {
            $data = str_pad($data, self::BLOCK, "\0");
        }

        return $data;
    }

    private function readData($gz, int $size): string
    {
        $out = '';
        $remaining = $size;

        while ($remaining > 0) {
            $buf = gzread($gz, min(self::COPY_CHUNK, $remaining));

            if (false === $buf || '' === $buf) {
                break;
            }

            $out .= $buf;
            $remaining -= \strlen($buf);
        }

        $this->skipPadding($gz, $size);

        return $out;
    }

    private function skip($gz, int $size): void
    {
        $remaining = $size;

        while ($remaining > 0) {
            $buf = gzread($gz, min(self::COPY_CHUNK, $remaining));

            if (false === $buf || '' === $buf) {
                break;
            }

            $remaining -= \strlen($buf);
        }

        $this->skipPadding($gz, $size);
    }

    private function skipPadding($gz, int $size): void
    {
        $pad = (self::BLOCK - ($size % self::BLOCK)) % self::BLOCK;

        if ($pad > 0) {
            gzread($gz, $pad);
        }
    }

    /**
     * @return array{name:string, size:int, typeflag:string, prefix:string}
     */
    private function parseHeader(string $header): array
    {
        $name = rtrim(substr($header, 0, 100), "\0");
        $size = $this->octal(substr($header, 124, 12));
        $typeflag = substr($header, 156, 1);
        $prefix = rtrim(substr($header, 345, 155), "\0");

        if ('' !== $prefix) {
            $name = $prefix.'/'.$name;
        }

        return ['name' => $name, 'size' => $size, 'typeflag' => $typeflag, 'prefix' => $prefix];
    }

    private function octal(string $field): int
    {
        $field = trim($field, "\0 ");

        return '' === $field ? 0 : (int) octdec($field);
    }

    /**
     * Reject absolute paths and traversal; normalise separators.
     */
    private function sanitise(string $name): string
    {
        $name = str_replace('\\', '/', $name);
        $name = ltrim($name, '/');
        $parts = [];

        foreach (explode('/', $name) as $part) {
            if ('' === $part || '.' === $part) {
                continue;
            }

            if ('..' === $part) {
                return ''; // refuse traversal outright
            }

            $parts[] = $part;
        }

        return implode('/', $parts);
    }
}
