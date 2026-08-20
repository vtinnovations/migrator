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

use Vtinnovations\Migrator\Archive\TarGzReader;
use Vtinnovations\Migrator\Config\Paths;
use Vtinnovations\Migrator\Job\Job;
use Vtinnovations\Migrator\Job\StepInterface;
use Vtinnovations\Migrator\Job\StepResult;

/**
 * Extracts the uploaded .tcmig package into the job staging dir. The .tcmig is itself a
 * tar.gz whose members are the manifest, db chunks, file chunks and secrets — so this is a
 * single streaming pass via TarGzReader (memory-flat, traversal-guarded).
 */
final class UnpackPackageStep implements StepInterface
{
    public function __construct(
        private readonly TarGzReader $reader,
        private readonly Paths $paths,
    ) {
    }

    public function name(): string
    {
        return 'unpack_package';
    }

    public function run(Job $job, float $deadline): StepResult
    {
        if (!empty($job->payload['unpacked'])) {
            return StepResult::completeStep('Package already unpacked.');
        }

        $archive = (string) ($job->meta['archive'] ?? '');

        if ('' === $archive || !is_file($archive)) {
            return StepResult::fail('Uploaded package not found: '.$archive);
        }

        $staging = $this->paths->stagingDir($job->id);
        $written = $this->reader->extract($archive, $staging);

        if (!is_file($staging.'/manifest.json')) {
            return StepResult::fail('Package has no manifest.json — not a valid .tcmig archive.');
        }

        $job->payload['unpacked'] = true;
        $job->payload['staging'] = $staging;

        return StepResult::completeStep(sprintf('Unpacked %d entries into staging.', \count($written)));
    }
}
