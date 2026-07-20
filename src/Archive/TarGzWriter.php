<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Archive;

/**
 * Minimal streaming tar writer wrapped in gzip. Files are copied in fixed-size blocks so
 * memory stays flat regardless of file size — required on Plesk hosts with low
 * memory_limit. Long paths (>100 bytes) use ustar name/prefix splitting, falling back to
 * a GNU '././@LongLink' entry when even that does not fit.
 */
final class TarGzWriter
{
    private const BLOCK = 512;
    private const COPY_CHUNK = 1048576; // 1 MiB read buffer

    /** @var resource */
    private $gz;

    private int $rawBytes = 0;

    public function __construct(string $path)
    {
        $gz = gzopen($path, 'wb6');

        if (false === $gz) {
            throw new \RuntimeException(sprintf('Could not open tar.gz "%s" for writing.', $path));
        }

        $this->gz = $gz;
    }

    /**
     * Uncompressed bytes written so far — used by the archiver to decide chunk rollover.
     */
    public function rawBytes(): int
    {
        return $this->rawBytes;
    }

    public function addFile(string $absPath, string $relPath): void
    {
        $size = filesize($absPath);

        if (false === $size) {
            throw new \RuntimeException(sprintf('Could not stat "%s".', $absPath));
        }

        $in = fopen($absPath, 'rb');

        if (false === $in) {
            throw new \RuntimeException(sprintf('Could not open "%s" for reading.', $absPath));
        }

        try {
            $this->writeHeader($relPath, $size, @fileperms($absPath) ?: 0644, @filemtime($absPath) ?: time());

            $written = 0;

            while (!feof($in)) {
                $buf = fread($in, self::COPY_CHUNK);

                if (false === $buf) {
                    throw new \RuntimeException(sprintf('Read error on "%s".', $absPath));
                }

                if ('' === $buf) {
                    break;
                }

                $this->raw($buf);
                $written += \strlen($buf);
            }

            if ($written !== $size) {
                throw new \RuntimeException(sprintf('Size mismatch archiving "%s" (%d vs %d).', $relPath, $written, $size));
            }

            $pad = (self::BLOCK - ($size % self::BLOCK)) % self::BLOCK;

            if ($pad > 0) {
                $this->raw(str_repeat("\0", $pad));
            }
        } finally {
            fclose($in);
        }
    }

    /**
     * Write the two zero blocks that terminate a tar archive and close the gzip stream.
     */
    public function close(): void
    {
        $this->raw(str_repeat("\0", self::BLOCK * 2));
        gzclose($this->gz);
    }

    private function writeHeader(string $name, int $size, int $mode, int $mtime): void
    {
        $name = str_replace('\\', '/', $name);
        $name = ltrim($name, '/');

        if (\strlen($name) > 100) {
            [$prefix, $short] = $this->splitName($name);

            if (null === $prefix) {
                $this->writeLongLinkHeader($name);
                $this->raw($this->buildHeader(substr($name, 0, 100), '', $size, $mode, $mtime));

                return;
            }

            $this->raw($this->buildHeader($short, $prefix, $size, $mode, $mtime));

            return;
        }

        $this->raw($this->buildHeader($name, '', $size, $mode, $mtime));
    }

    /**
     * @return array{0: ?string, 1: string} [prefix|null, name]
     */
    private function splitName(string $name): array
    {
        $split = strrpos(substr($name, 0, 156), '/');

        if (false === $split) {
            return [null, $name];
        }

        $prefix = substr($name, 0, $split);
        $short = substr($name, $split + 1);

        if (\strlen($prefix) <= 155 && \strlen($short) <= 100) {
            return [$prefix, $short];
        }

        return [null, $name];
    }

    private function writeLongLinkHeader(string $name): void
    {
        $data = $name."\0";
        $this->raw($this->buildHeader('././@LongLink', '', \strlen($data), 0644, 0, 'L'));
        $this->raw($data);
        $pad = (self::BLOCK - (\strlen($data) % self::BLOCK)) % self::BLOCK;

        if ($pad > 0) {
            $this->raw(str_repeat("\0", $pad));
        }
    }

    private function buildHeader(string $name, string $prefix, int $size, int $mode, int $mtime, string $typeflag = '0'): string
    {
        $header = pack('a100', $name);
        $header .= pack('a8', sprintf('%07o', $mode & 0777));
        $header .= pack('a8', sprintf('%07o', 0));   // uid
        $header .= pack('a8', sprintf('%07o', 0));   // gid
        $header .= pack('a12', sprintf('%011o', $size));
        $header .= pack('a12', sprintf('%011o', $mtime));
        $header .= str_repeat(' ', 8);               // checksum placeholder
        $header .= pack('a1', $typeflag);
        $header .= pack('a100', '');                 // linkname
        $header .= pack('a6', 'ustar');
        $header .= pack('a2', '00');
        $header .= pack('a32', '');                  // uname
        $header .= pack('a32', '');                  // gname
        $header .= pack('a8', '');                   // devmajor
        $header .= pack('a8', '');                   // devminor
        $header .= pack('a155', $prefix);
        $header .= str_repeat("\0", 12);             // pad to 512

        $checksum = 0;

        for ($i = 0; $i < self::BLOCK; ++$i) {
            $checksum += \ord($header[$i]);
        }

        // Overwrite the 8-byte checksum field (offset 148): 6 octal digits, NUL, space.
        $checkField = sprintf('%06o', $checksum)."\0 ";

        return substr_replace($header, $checkField, 148, 8);
    }

    private function raw(string $bytes): void
    {
        if ('' === $bytes) {
            return;
        }

        if (false === gzwrite($this->gz, $bytes)) {
            throw new \RuntimeException('gzwrite failed while building tar.');
        }

        $this->rawBytes += \strlen($bytes);
    }
}
