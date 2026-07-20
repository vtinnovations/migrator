<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Job\Step;

use Vtinnovations\Migrator\Config\Paths;
use Vtinnovations\Migrator\Job\Job;
use Vtinnovations\Migrator\Job\StepInterface;
use Vtinnovations\Migrator\Job\StepResult;

/**
 * Last cheap safety net before destructive work begins: copies the destination's current
 * env files (.env, .env.local) into the job's snapshot dir so a botched import can restore
 * the original DB coordinates and secrets by hand.
 *
 * A full destination DB snapshot is deliberately NOT taken here — it would double the import
 * time and disk, and the operator is expected to have their own backup before a migration.
 * The env snapshot is what actually prevents a lock-out (wrong DATABASE_URL after reconcile).
 */
final class SafetySnapshotStep implements StepInterface
{
    private const ENV_FILES = ['.env', '.env.local'];

    public function __construct(
        private readonly Paths $paths,
        private readonly string $projectDir,
    ) {
    }

    public function name(): string
    {
        return 'safety_snapshot';
    }

    public function run(Job $job, float $deadline): StepResult
    {
        $dir = $this->paths->snapshotsDir().'/'.substr($job->id, 0, 8);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return StepResult::fail('Could not create snapshot dir.');
        }

        $root = rtrim($this->projectDir, '/\\');
        $copied = [];

        foreach (self::ENV_FILES as $rel) {
            $src = $root.'/'.$rel;

            if (!is_file($src)) {
                continue;
            }

            $dst = $dir.'/'.$rel;

            if (false === @copy($src, $dst)) {
                return StepResult::fail('Could not snapshot '.$rel.'.');
            }

            @chmod($dst, 0600);
            $copied[] = $rel;
        }

        $job->meta['snapshot'] = ['dir' => $dir, 'files' => $copied];

        return StepResult::completeStep(sprintf('Snapshotted %d env file(s) for rollback.', \count($copied)));
    }
}
