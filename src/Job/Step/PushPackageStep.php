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

namespace Vtinnovations\Migrator\Job\Step;

use Vtinnovations\Migrator\Config\ConfigStore;
use Vtinnovations\Migrator\Job\Job;
use Vtinnovations\Migrator\Job\StepInterface;
use Vtinnovations\Migrator\Job\StepResult;
use Vtinnovations\Migrator\Transfer\PairingStore;
use Vtinnovations\Migrator\Transfer\PayloadTooLargeException;
use Vtinnovations\Migrator\Transfer\PushClient;

/**
 * Mode B step: streams the built .tcmig to the destination's ingest endpoint in signed chunks,
 * resumable across time-budget slices. Progress is tracked by BYTE OFFSET (not a fixed index ×
 * size) so the chunk size can vary mid-transfer: if the destination answers 413 (body over its
 * nginx client_max_body_size / PHP post_max_size), the chunk size is halved down to a 64 KiB
 * floor and the same offset is retried. Indices stay sequential regardless of size, and the
 * remote assembler concatenates the raw chunks in order, so mixed sizes reassemble correctly.
 * Once every byte has landed it calls finalize, which makes the remote assemble + verify +
 * enqueue its import job; the returned remote jobId is recorded on this job.
 *
 * Required meta: 'remoteUrl' (destination base URL), 'pairing' ("{session}.{key}" token),
 * 'package'/'packageSha'/'packageBytes' (set by BuildPackageStep).
 */
final class PushPackageStep implements StepInterface
{
    /** Never shrink a chunk below this — if the destination rejects even this, it's misconfigured. */
    private const FLOOR_BYTES = 65536; // 64 KiB

    public function __construct(
        private readonly PushClient $client,
        private readonly ConfigStore $config,
    ) {
    }

    public function name(): string
    {
        return 'push_package';
    }

    public function run(Job $job, float $deadline): StepResult
    {
        $package = (string) ($job->meta['package'] ?? '');
        $remoteUrl = (string) ($job->meta['remoteUrl'] ?? '');
        $token = (string) ($job->meta['pairing'] ?? '');
        $sha = (string) ($job->meta['packageSha'] ?? '');

        if ('' === $package || !is_file($package)) {
            return StepResult::fail('Built package missing for push.');
        }

        if ('' === $remoteUrl || '' === $token) {
            return StepResult::fail('Mode B push needs remoteUrl and pairing token.');
        }

        $parsed = PairingStore::parseToken($token);

        if (null === $parsed) {
            return StepResult::fail('Malformed pairing token (expected "session.key").');
        }

        $size = (int) ($job->meta['packageBytes'] ?? filesize($package));
        $offset = (int) ($job->payload['pushOffset'] ?? 0);
        $index = (int) ($job->payload['pushIndex'] ?? 0);
        $chunkBytes = (int) ($job->payload['pushChunkBytes'] ?? max(self::FLOOR_BYTES, (int) $this->config->get('push_chunk_bytes', 4194304)));

        $fh = fopen($package, 'rb');

        if (false === $fh) {
            return StepResult::fail('Could not open package for push.');
        }

        try {
            while ($offset < $size) {
                fseek($fh, $offset);
                $bytes = (string) fread($fh, $chunkBytes);
                $len = \strlen($bytes);

                if (0 === $len) {
                    return StepResult::fail('Unexpected empty read while pushing package.');
                }

                try {
                    $this->client->pushChunk($remoteUrl, $parsed['session'], $parsed['key'], $index, $bytes);
                } catch (PayloadTooLargeException $e) {
                    if ($chunkBytes <= self::FLOOR_BYTES) {
                        $this->persist($job, $offset, $index, $chunkBytes);

                        return StepResult::fail(sprintf(
                            'Destination rejected even a %d KiB chunk (HTTP 413). Raise the destination web server\'s client_max_body_size / post_max_size.',
                            (int) (self::FLOOR_BYTES / 1024)
                        ));
                    }

                    // Halve and retry the SAME offset with the smaller size (index not advanced).
                    $chunkBytes = max(self::FLOOR_BYTES, intdiv($chunkBytes, 2));
                    $this->persist($job, $offset, $index, $chunkBytes);

                    continue;
                } catch (\Throwable $e) {
                    $this->persist($job, $offset, $index, $chunkBytes);

                    return StepResult::fail($e->getMessage());
                }

                $offset += $len;
                ++$index;
                $this->persist($job, $offset, $index, $chunkBytes); // crash-safe per landed chunk

                if (microtime(true) >= $deadline) {
                    break;
                }
            }
        } finally {
            fclose($fh);
        }

        if ($offset < $size) {
            return StepResult::continueStep(sprintf('Pushed %d/%d bytes in %d chunks.', $offset, $size, $index));
        }

        try {
            $result = $this->client->finalize($remoteUrl, $parsed['session'], $parsed['key'], $index, $sha);
        } catch (\Throwable $e) {
            return StepResult::fail('Finalize rejected: '.$e->getMessage());
        }

        unset($job->payload['pushOffset'], $job->payload['pushIndex'], $job->payload['pushChunkBytes']);
        $job->meta['remoteJobId'] = $result['jobId'] ?? null;

        return StepResult::completeStep(sprintf('Transfer complete — remote import job %s.', $job->meta['remoteJobId'] ?? '?'));
    }

    private function persist(Job $job, int $offset, int $index, int $chunkBytes): void
    {
        $job->payload['pushOffset'] = $offset;
        $job->payload['pushIndex'] = $index;
        $job->payload['pushChunkBytes'] = $chunkBytes;
    }
}
