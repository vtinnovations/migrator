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

use Vtinnovations\Migrator\Config\Paths;
use Vtinnovations\Migrator\Job\Job;
use Vtinnovations\Migrator\Job\StepInterface;
use Vtinnovations\Migrator\Job\StepResult;

/**
 * Closes out an import: burns the transient passphrase, deletes the unpacked staging tree to
 * reclaim disk, and records a completion marker. The uploaded .tcmig is left in place so the
 * operator can retry if they later spot a problem; it is pruned by retention, not here.
 */
final class FinalizeImportStep implements StepInterface
{
    public function __construct(private readonly Paths $paths)
    {
    }

    public function name(): string
    {
        return 'finalize_import';
    }

    public function run(Job $job, float $deadline): StepResult
    {
        @unlink($this->paths->jobSecretFile($job->id));

        $staging = (string) ($job->payload['staging'] ?? $this->paths->stagingDir($job->id));
        $this->rrmdir($staging);

        $job->meta['importComplete'] = true;
        $job->meta['finishedAt'] = gmdate('c');

        return StepResult::completeStep('Migration import complete. Verify the site, then run "contao:migrate".');
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
}
