<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Config;

/**
 * Resolves all working paths under the configured scratch dir (default var/migrator).
 * Creates directories on demand. Never commit this tree.
 */
final class Paths
{
    private readonly string $scratchDir;

    public function __construct(string $scratchDir)
    {
        $this->scratchDir = rtrim($scratchDir, '/\\');
    }

    public function scratch(): string
    {
        return $this->ensure($this->scratchDir);
    }

    public function jobsDir(): string
    {
        return $this->ensure($this->scratchDir.'/jobs');
    }

    public function jobFile(string $jobId): string
    {
        return $this->jobsDir().'/'.$this->safe($jobId).'.json';
    }

    public function backupsDir(): string
    {
        return $this->ensure($this->scratchDir.'/backups');
    }

    public function backupDir(string $jobId): string
    {
        return $this->ensure($this->backupsDir().'/'.$this->safe($jobId));
    }

    public function stagingDir(string $jobId): string
    {
        return $this->ensure($this->scratchDir.'/staging/'.$this->safe($jobId));
    }

    public function snapshotsDir(): string
    {
        return $this->ensure($this->scratchDir.'/snapshots');
    }

    public function incomingDir(string $session): string
    {
        return $this->ensure($this->scratchDir.'/incoming/'.$this->safe($session));
    }

    public function configFile(): string
    {
        return $this->scratch().'/config.json';
    }

    /**
     * Transient 0600 file holding a job's vault passphrase. Written by the controller at
     * export/import start and deleted by the secrets step — never stored in the job JSON.
     */
    public function jobSecretFile(string $jobId): string
    {
        return $this->jobsDir().'/'.$this->safe($jobId).'.secret';
    }

    /**
     * Marker file requesting cancellation of a running job. The runner checks it each slice and
     * aborts mid-tick; written lock-free so a cancel is instant even while a tick holds the lock.
     */
    public function jobCancelFile(string $jobId): string
    {
        return $this->jobsDir().'/'.$this->safe($jobId).'.cancel';
    }

    public function packageFile(string $jobId): string
    {
        return $this->scratch().'/'.$this->safe($jobId).'.tcmig';
    }

    public function tokenFile(): string
    {
        return $this->scratch().'/auth.token';
    }

    public function pairingFile(): string
    {
        return $this->scratch().'/pairings.json';
    }

    public function licenseFile(): string
    {
        return $this->scratch().'/license.json';
    }

    /**
     * Strip path separators and traversal from an externally-supplied id segment.
     */
    private function safe(string $segment): string
    {
        $segment = str_replace(['/', '\\', "\0"], '', $segment);
        $segment = preg_replace('/\.\.+/', '', $segment) ?? '';

        if ('' === $segment) {
            throw new \InvalidArgumentException('Empty path segment after sanitisation.');
        }

        return $segment;
    }

    private function ensure(string $dir): string
    {
        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException(sprintf('Could not create directory "%s".', $dir));
        }

        return $dir;
    }
}
