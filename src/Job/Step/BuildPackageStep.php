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

use Vtinnovations\Migrator\Job\Job;
use Vtinnovations\Migrator\Job\StepInterface;
use Vtinnovations\Migrator\Job\StepResult;
use Vtinnovations\Migrator\Transfer\PackageBuilder;

/**
 * Mode B step: materialises the finished export into a single {jobId}.tcmig so the push step
 * has one file to stream + checksum. Records its path, size and sha256 on the job.
 */
final class BuildPackageStep implements StepInterface
{
    public function __construct(private readonly PackageBuilder $builder)
    {
    }

    public function name(): string
    {
        return 'build_package';
    }

    public function run(Job $job, float $deadline): StepResult
    {
        $existing = (string) ($job->meta['package'] ?? '');

        if ('' !== $existing && is_file($existing)) {
            return StepResult::completeStep('Package already built.');
        }

        $path = $this->builder->build($job->id);
        $sha = hash_file('sha256', $path);

        if (false === $sha) {
            return StepResult::fail('Could not checksum built package.');
        }

        $job->meta['package'] = $path;
        $job->meta['packageSha'] = $sha;
        $job->meta['packageBytes'] = (int) filesize($path);

        return StepResult::completeStep(sprintf('Package built (%d bytes).', $job->meta['packageBytes']));
    }
}
