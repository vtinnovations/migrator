<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Job\Step;

use Vtinnovations\Migrator\Archive\FileArchiver;
use Vtinnovations\Migrator\Archive\TarGzWriter;
use Vtinnovations\Migrator\Config\Paths;
use Vtinnovations\Migrator\Job\Job;
use Vtinnovations\Migrator\Job\StepInterface;
use Vtinnovations\Migrator\Job\StepResult;
use Vtinnovations\Migrator\Manifest\Manifest;
use Vtinnovations\Migrator\Manifest\ManifestStore;

/**
 * Streams the project tree into chunked files/files.{NNN}.tar.gz under archive_chunk_bytes.
 * The file list is enumerated once into a sidecar (files-index.json) so the job payload
 * stays small; each slice writes one complete chunk and records its sha256 in the manifest.
 */
final class ArchiveFilesStep implements StepInterface
{
    public function __construct(
        private readonly FileArchiver $archiver,
        private readonly ManifestStore $manifests,
        private readonly Paths $paths,
    ) {
    }

    public function name(): string
    {
        return 'archive_files';
    }

    public function run(Job $job, float $deadline): StepResult
    {
        $manifest = $this->manifests->load($job->id) ?? new Manifest($job->id);
        $state = $job->payload['archive'] ?? null;

        if (null === $state) {
            $state = $this->begin($job, $manifest, !empty($job->meta['composerFix']));
            $job->payload['archive'] = $state;

            $note = !empty($job->meta['composerFix']) ? ' (incl. vendor/ — composer-fix)' : '';

            return StepResult::continueStep(sprintf('Enumerated %d files for archiving%s.', $state['total'], $note));
        }

        $index = $this->loadIndex($job->id);
        $total = \count($index);

        if ($state['fileIndex'] >= $total) {
            return $this->complete($job, $manifest, $total);
        }

        $chunkBytes = $this->archiver->chunkBytes();
        $root = $this->archiver->projectDir();
        $rel = sprintf('files/files.%03d.tar.gz', $state['chunkIndex']);
        $abs = $this->paths->backupDir($job->id).'/'.$rel;

        if (!is_dir(\dirname($abs)) && !@mkdir(\dirname($abs), 0775, true) && !is_dir(\dirname($abs))) {
            return StepResult::fail('Could not create files/ dir.');
        }

        $writer = new TarGzWriter($abs);
        $startIndex = $state['fileIndex'];
        $added = 0;

        try {
            while ($state['fileIndex'] < $total) {
                $entry = $index[$state['fileIndex']];
                $absFile = $root.'/'.$entry['path'];

                if (is_file($absFile)) {
                    $writer->addFile($absFile, $entry['path']);
                    ++$added;
                }

                ++$state['fileIndex'];

                if ($writer->rawBytes() >= $chunkBytes || microtime(true) >= $deadline) {
                    break;
                }
            }
        } finally {
            $writer->close();
        }

        $manifest->addFileChunk([
            'file' => $rel,
            'bytes' => (int) filesize($abs),
            'sha256' => hash_file('sha256', $abs),
            'fileCount' => $added,
            'fromIndex' => $startIndex,
            'toIndex' => $state['fileIndex'] - 1,
        ]);
        $this->manifests->save($job->id, $manifest);

        ++$state['chunkIndex'];
        $job->payload['archive'] = $state;

        if ($state['fileIndex'] >= $total) {
            return $this->complete($job, $manifest, $total);
        }

        return StepResult::continueStep(sprintf('Archived %d/%d files.', $state['fileIndex'], $total));
    }

    /**
     * @return array<string, mixed>
     */
    private function begin(Job $job, Manifest $manifest, bool $includeVendor = false): array
    {
        $files = $this->archiver->enumerate($includeVendor);
        $totalBytes = array_sum(array_column($files, 'size'));

        $indexFile = $this->paths->backupDir($job->id).'/files-index.json';
        file_put_contents($indexFile, json_encode($files, JSON_UNESCAPED_SLASHES) ?: '[]', LOCK_EX);

        $files0 = $manifest->get('files', ['chunks' => []]);
        $files0['chunks'] = [];
        $files0['totalFiles'] = \count($files);
        $files0['totalBytes'] = $totalBytes;
        $manifest->set('files', $files0);
        $this->manifests->save($job->id, $manifest);

        return [
            'fileIndex' => 0,
            'chunkIndex' => 0,
            'total' => \count($files),
        ];
    }

    /**
     * @return list<array{path:string, size:int}>
     */
    private function loadIndex(string $jobId): array
    {
        $file = $this->paths->backupDir($jobId).'/files-index.json';
        $raw = is_file($file) ? file_get_contents($file) : false;
        $data = false !== $raw ? json_decode($raw, true) : null;

        return \is_array($data) ? $data : [];
    }

    private function complete(Job $job, Manifest $manifest, int $total): StepResult
    {
        @unlink($this->paths->backupDir($job->id).'/files-index.json');
        unset($job->payload['archive']);

        return StepResult::completeStep(sprintf('File archive complete (%d files).', $total));
    }
}
