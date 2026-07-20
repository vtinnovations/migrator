<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Job;

use Vtinnovations\Migrator\Config\Paths;

/**
 * Builds jobs with their canonical ordered step lists and seeds job metadata.
 *
 * The export passphrase (if any) is written to a transient 0600 sidecar — never into the
 * job JSON — and consumed by SecretsStep / FinalizeExportStep.
 */
final class JobFactory
{
    /** @var list<string> */
    private const EXPORT_STEPS = [
        'preflight',
        'dump_database',
        'archive_files',
        'secrets',
        'finalize_export',
    ];

    /** @var list<string> Mode B source: full export, then build + push the package. */
    private const PUSH_STEPS = [
        'preflight',
        'dump_database',
        'archive_files',
        'secrets',
        'finalize_export',
        'build_package',
        'push_package',
    ];

    /** @var list<string> */
    private const IMPORT_STEPS = [
        'unpack_package',
        'verify_package',
        'compat_gate',
        'capture_dest_config',
        'safety_snapshot',
        'extract_files',
        'restore_database',
        'url_replace',
        'config_reconcile',
        'post_migration',
        'finalize_import',
    ];

    public function __construct(
        private readonly JobStore $store,
        private readonly Paths $paths,
    ) {
    }

    /**
     * @param array<string, mixed> $meta
     */
    public function createExport(array $meta = [], ?string $passphrase = null): Job
    {
        $job = $this->store->create(JobType::Export);
        $job->steps = self::EXPORT_STEPS;
        $job->meta = array_merge(['mode' => 'A', 'createdBy' => 'export', 'lang' => $this->lang()], $meta);
        $this->store->save($job);

        $this->writePassphrase($job->id, $passphrase);

        return $job;
    }

    /**
     * @param array<string, mixed> $meta the 'archive' key MUST point at the uploaded .tcmig
     */
    public function createImport(array $meta = [], ?string $passphrase = null): Job
    {
        $job = $this->store->create(JobType::Import);
        $job->steps = self::IMPORT_STEPS;
        $job->meta = array_merge(['mode' => 'A', 'createdBy' => 'import', 'lang' => $this->lang()], $meta);
        $this->store->save($job);

        $this->writePassphrase($job->id, $passphrase);

        return $job;
    }

    /**
     * Mode B source job: export → build .tcmig → push to a paired destination.
     *
     * @param array<string, mixed> $meta must include 'remoteUrl' and 'pairing' ("session.key")
     */
    public function createPush(array $meta = [], ?string $passphrase = null): Job
    {
        $job = $this->store->create(JobType::Export);
        $job->steps = self::PUSH_STEPS;
        $job->meta = array_merge(['mode' => 'B', 'createdBy' => 'push', 'lang' => $this->lang()], $meta);
        $this->store->save($job);

        $this->writePassphrase($job->id, $passphrase);

        return $job;
    }

    /**
     * The backend user's language at job-creation time ('de' or 'en'), so every message this job
     * later logs renders in that language regardless of whether a browser or a cron drives it.
     */
    private function lang(): string
    {
        return str_starts_with((string) ($GLOBALS['TL_LANGUAGE'] ?? 'en'), 'de') ? 'de' : 'en';
    }

    private function writePassphrase(string $jobId, ?string $passphrase): void
    {
        if (null === $passphrase || '' === $passphrase) {
            return;
        }

        $file = $this->paths->jobSecretFile($jobId);
        file_put_contents($file, $passphrase, LOCK_EX);
        @chmod($file, 0600);
    }
}
