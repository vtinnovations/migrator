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
use Vtinnovations\Migrator\Manifest\ManifestStore;

/**
 * Lays the source project tree over the destination by extracting each
 * files/files.{NNN}.tar.gz chunk into the project dir. Resumable a chunk at a time
 * (payload['extractIndex']); each tar.gz is a self-contained unit so a killed request
 * re-extracts only the next chunk, never a half file (TarGzReader streams whole entries).
 *
 * This intentionally overwrites .env.local / config with the source's — ConfigReconcileStep
 * repairs the destination-owned keys afterwards from the snapshot captured earlier.
 */
final class ExtractFilesStep implements StepInterface
{
    public function __construct(
        private readonly TarGzReader $reader,
        private readonly ManifestStore $manifests,
        private readonly Paths $paths,
        private readonly string $projectDir,
    ) {
    }

    public function name(): string
    {
        return 'extract_files';
    }

    public function run(Job $job, float $deadline): StepResult
    {
        $staging = (string) ($job->payload['staging'] ?? $this->paths->stagingDir($job->id));
        $manifest = $this->manifests->loadPath($staging.'/manifest.json');

        if (null === $manifest) {
            return StepResult::fail('Manifest missing for extract.');
        }

        $chunks = $manifest->get('files')['chunks'] ?? [];
        $total = \count($chunks);
        $index = (int) ($job->payload['extractIndex'] ?? 0);

        if (0 === $total) {
            return StepResult::completeStep('No file chunks to extract.');
        }

        $root = rtrim($this->projectDir, '/\\');
        $extracted = 0;

        while ($index < $total) {
            $chunk = $chunks[$index];
            $abs = $staging.'/'.$chunk['file'];

            if (!is_file($abs)) {
                return StepResult::fail('Missing file chunk: '.$chunk['file']);
            }

            $this->reader->extract($abs, $root);
            $extracted += (int) ($chunk['fileCount'] ?? 0);
            ++$index;

            if (microtime(true) >= $deadline) {
                break;
            }
        }

        $job->payload['extractIndex'] = $index;

        if ($index < $total) {
            return StepResult::continueStep(sprintf('Extracted %d/%d file chunk(s).', $index, $total));
        }

        unset($job->payload['extractIndex']);

        return StepResult::completeStep(sprintf('All %d file chunk(s) extracted.', $total));
    }
}
