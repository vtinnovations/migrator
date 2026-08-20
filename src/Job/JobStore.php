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

namespace Vtinnovations\Migrator\Job;

use Vtinnovations\Migrator\Config\Paths;

/**
 * Persists jobs as JSON under var/migrator/jobs/{uuid}.json.
 *
 * Writes are atomic (temp file + rename). withLock() serialises advancement so a cron
 * tick and an AJAX poll never advance the same job concurrently; it returns null if the
 * lock is already held — callers must back off rather than block.
 */
final class JobStore
{
    public function __construct(private readonly Paths $paths)
    {
    }

    public function create(JobType $type): Job
    {
        $job = new Job($this->uuid(), $type);
        $this->save($job);

        return $job;
    }

    public function load(string $jobId): ?Job
    {
        $file = $this->paths->jobFile($jobId);

        if (!is_file($file)) {
            return null;
        }

        $raw = file_get_contents($file);

        if (false === $raw || '' === $raw) {
            return null;
        }

        $data = json_decode($raw, true);

        if (!\is_array($data)) {
            return null;
        }

        return Job::fromArray($data);
    }

    public function save(Job $job): void
    {
        $job->updatedAt = microtime(true);
        $file = $this->paths->jobFile($job->id);
        $tmp = $file.'.'.bin2hex(random_bytes(4)).'.tmp';

        $json = json_encode($job, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $json) {
            throw new \RuntimeException('Failed to encode job to JSON: '.json_last_error_msg());
        }

        if (false === file_put_contents($tmp, $json, LOCK_EX)) {
            throw new \RuntimeException(sprintf('Failed to write job file "%s".', $tmp));
        }

        if (!@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException(sprintf('Failed to move job file into place "%s".', $file));
        }
    }

    /**
     * @return list<string>
     */
    public function listIds(): array
    {
        $ids = [];

        foreach (glob($this->paths->jobsDir().'/*.json') ?: [] as $file) {
            $ids[] = basename($file, '.json');
        }

        return $ids;
    }

    /**
     * Request cancellation of a job. Writes a lock-free marker the runner consumes mid-tick; if the
     * job is not currently being ticked (lock free), flips it to Cancelled immediately for instant
     * UI feedback. A running tick will notice the marker within its next slice.
     */
    public function requestCancel(string $jobId): void
    {
        @touch($this->paths->jobCancelFile($jobId));

        $this->withLock($jobId, function () use ($jobId): void {
            $job = $this->load($jobId);

            if (null !== $job && !$job->state->isTerminal()) {
                $job->state = JobState::Cancelled;
                $job->error = 'Cancelled by operator.';
                $job->addLog('Cancelled by operator.', 'warning');
                $this->save($job);
                @unlink($this->paths->jobCancelFile($jobId));
            }
        });
    }

    /** True (and removes the marker) if a cancellation was requested for this job. */
    public function consumeCancel(string $jobId): bool
    {
        $file = $this->paths->jobCancelFile($jobId);

        if (!is_file($file)) {
            return false;
        }

        @unlink($file);

        return true;
    }

    /**
     * Remove a job and every artefact keyed by its id: the JSON, its lock/secret/cancel sidecars,
     * the built .tcmig, and its backup + staging dirs. Best-effort — a missing piece is fine.
     */
    public function delete(string $jobId): void
    {
        foreach ([
            $this->paths->jobFile($jobId),
            $this->paths->jobFile($jobId).'.lock',
            $this->paths->jobSecretFile($jobId),
            $this->paths->jobCancelFile($jobId),
            $this->paths->packageFile($jobId),
        ] as $file) {
            @unlink($file);
        }

        foreach ([$this->paths->backupDir($jobId), $this->paths->stagingDir($jobId)] as $dir) {
            $this->rrmdir($dir);
        }
    }

    private function rrmdir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }

        @rmdir($dir);
    }

    /**
     * Run $fn while holding an exclusive lock on the job. Returns null without calling
     * $fn if the lock is held by another process.
     *
     * @template T
     *
     * @param callable(): T $fn
     *
     * @return T|null
     */
    public function withLock(string $jobId, callable $fn): mixed
    {
        $lockFile = $this->paths->jobFile($jobId).'.lock';
        $handle = fopen($lockFile, 'c');

        if (false === $handle) {
            throw new \RuntimeException(sprintf('Could not open lock file "%s".', $lockFile));
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

    private function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0F) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
