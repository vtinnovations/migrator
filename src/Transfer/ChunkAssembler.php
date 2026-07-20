<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Transfer;

use Vtinnovations\Migrator\Config\Paths;

/**
 * Receives Mode B payload chunks into a per-session incoming dir and reassembles them into a
 * single .tcmig once the source signals finalize. Chunks are written as discrete numbered
 * files so an interrupted push can resume by re-sending only the missing indexes; assembly
 * concatenates them in order and verifies the whole-payload sha256 before handing the file
 * to the import pipeline.
 */
final class ChunkAssembler
{
    private const COPY_CHUNK = 1048576;

    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * Persist one chunk. Returns true if newly written, false if that index already existed
     * (idempotent re-send).
     */
    public function append(string $session, int $index, string $bytes): bool
    {
        $path = $this->chunkPath($session, $index);

        if (is_file($path)) {
            return false;
        }

        $tmp = $path.'.tmp';

        if (false === file_put_contents($tmp, $bytes, LOCK_EX) || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not store chunk '.$index.'.');
        }

        return true;
    }

    /**
     * Persist one chunk by copying an existing file (e.g. an uploaded Mode A volume) into place,
     * avoiding loading the whole part into memory. Returns false if that index already existed.
     */
    public function appendFile(string $session, int $index, string $sourcePath): bool
    {
        $path = $this->chunkPath($session, $index);

        if (is_file($path)) {
            return false;
        }

        $tmp = $path.'.tmp';

        if (!@copy($sourcePath, $tmp) || !@rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not store chunk '.$index.'.');
        }

        return true;
    }

    /**
     * @return array{count:int, bytes:int}
     */
    public function state(string $session): array
    {
        $files = glob($this->paths->incomingDir($session).'/chunk.*') ?: [];
        $bytes = 0;

        foreach ($files as $f) {
            $bytes += (int) filesize($f);
        }

        return ['count' => \count($files), 'bytes' => $bytes];
    }

    /**
     * Concatenate chunk.0..N-1 in order into payload.tcmig. When $expectedSha is given the
     * whole-payload sha256 is verified (Mode B transport integrity); for Mode A local part
     * uploads it may be null since the import pipeline verifies the manifest and per-chunk
     * checksums inside the package anyway.
     *
     * @return string absolute path of the assembled .tcmig
     */
    public function assemble(string $session, int $count, ?string $expectedSha = null): string
    {
        $dir = $this->paths->incomingDir($session);
        $out = $dir.'/payload.tcmig';

        $dst = fopen($out, 'wb');

        if (false === $dst) {
            throw new \RuntimeException('Could not open assembly target.');
        }

        $ctx = hash_init('sha256');

        try {
            for ($i = 0; $i < $count; ++$i) {
                $part = $this->chunkPath($session, $i);

                if (!is_file($part)) {
                    throw new \RuntimeException('Missing chunk '.$i.' during assembly.');
                }

                $src = fopen($part, 'rb');

                if (false === $src) {
                    throw new \RuntimeException('Could not read chunk '.$i.'.');
                }

                try {
                    while (!feof($src)) {
                        $buf = fread($src, self::COPY_CHUNK);

                        if (false === $buf) {
                            break;
                        }

                        hash_update($ctx, $buf);
                        fwrite($dst, $buf);
                    }
                } finally {
                    fclose($src);
                }
            }
        } finally {
            fclose($dst);
        }

        $actual = hash_final($ctx);

        if (null !== $expectedSha && !hash_equals($expectedSha, $actual)) {
            @unlink($out);

            throw new \RuntimeException('Assembled payload checksum mismatch (corrupt or tampered transfer).');
        }

        return $out;
    }

    public function discardChunks(string $session): void
    {
        foreach (glob($this->paths->incomingDir($session).'/chunk.*') ?: [] as $f) {
            @unlink($f);
        }
    }

    private function chunkPath(string $session, int $index): string
    {
        return $this->paths->incomingDir($session).sprintf('/chunk.%06d', $index);
    }
}
