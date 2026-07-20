<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Job\Step;

use Vtinnovations\Migrator\Config\Paths;
use Vtinnovations\Migrator\Database\DatabaseRestorer;
use Vtinnovations\Migrator\Job\Job;
use Vtinnovations\Migrator\Job\StepInterface;
use Vtinnovations\Migrator\Job\StepResult;
use Vtinnovations\Migrator\Manifest\ManifestStore;

/**
 * Restores the dumped database into the destination connection. Two phases, both resumable:
 *   - schema: DROP + CREATE every table from db/schema.sql.
 *   - data:   replay each gzipped INSERT chunk, one at a time.
 *
 * Foreign-key checks are suspended for the duration of each slice (they are connection-
 * scoped, so they are re-disabled at the start of every tick) and re-enabled once the last
 * data chunk has loaded.
 */
final class RestoreDatabaseStep implements StepInterface
{
    public function __construct(
        private readonly DatabaseRestorer $restorer,
        private readonly ManifestStore $manifests,
        private readonly Paths $paths,
    ) {
    }

    public function name(): string
    {
        return 'restore_database';
    }

    public function run(Job $job, float $deadline): StepResult
    {
        $staging = (string) ($job->payload['staging'] ?? $this->paths->stagingDir($job->id));
        $manifest = $this->manifests->loadPath($staging.'/manifest.json');

        if (null === $manifest) {
            return StepResult::fail('Manifest missing for DB restore.');
        }

        $this->restorer->disableForeignKeys();

        $state = $job->payload['restore'] ?? ['phase' => 'schema', 'index' => 0];

        if ('schema' === $state['phase']) {
            return $this->runSchema($job, $staging, $manifest, $state, $deadline);
        }

        return $this->runData($job, $staging, $manifest, $state, $deadline);
    }

    /**
     * @param array<string, mixed> $state
     */
    private function runSchema(Job $job, string $staging, $manifest, array $state, float $deadline): StepResult
    {
        $schemaRel = $manifest->get('database')['schemaFile'] ?? 'db/schema.sql';
        $schemaPath = $staging.'/'.$schemaRel;

        if (!is_file($schemaPath)) {
            return StepResult::fail('Schema file missing: '.$schemaRel);
        }

        $schema = $this->restorer->parseSchema((string) file_get_contents($schemaPath));
        $names = array_keys($schema);
        $stmts = array_values($schema);
        $total = \count($schema);
        $index = (int) $state['index'];

        while ($index < $total && microtime(true) < $deadline) {
            $this->restorer->dropAndCreate($names[$index], $stmts[$index]);
            ++$index;
        }

        if ($index < $total) {
            $job->payload['restore'] = ['phase' => 'schema', 'index' => $index];

            return StepResult::continueStep(sprintf('Created %d/%d tables.', $index, $total));
        }

        $job->payload['restore'] = ['phase' => 'data', 'index' => 0];

        return StepResult::continueStep(sprintf('Schema restored (%d tables), loading data.', $total));
    }

    /**
     * @param array<string, mixed> $state
     */
    private function runData(Job $job, string $staging, $manifest, array $state, float $deadline): StepResult
    {
        $chunks = $this->dataChunks($manifest);
        $total = \count($chunks);
        $index = (int) $state['index'];

        if (0 === $total) {
            return $this->finish($job, 0);
        }

        while ($index < $total && microtime(true) < $deadline) {
            $abs = $staging.'/'.$chunks[$index];

            if (!is_file($abs)) {
                return StepResult::fail('Missing data chunk: '.$chunks[$index]);
            }

            $this->restorer->loadChunk($abs);
            ++$index;
        }

        if ($index < $total) {
            $job->payload['restore'] = ['phase' => 'data', 'index' => $index];

            return StepResult::continueStep(sprintf('Loaded %d/%d data chunks.', $index, $total));
        }

        return $this->finish($job, $total);
    }

    private function finish(Job $job, int $chunks): StepResult
    {
        $this->restorer->enableForeignKeys();
        unset($job->payload['restore']);

        return StepResult::completeStep(sprintf('Database restored (%d data chunks).', $chunks));
    }

    /**
     * Flatten every table's data chunks into one ordered list of staging-relative paths.
     *
     * @return list<string>
     */
    private function dataChunks($manifest): array
    {
        $out = [];

        foreach (($manifest->get('database')['tables'] ?? []) as $info) {
            foreach (($info['chunks'] ?? []) as $chunk) {
                if (isset($chunk['file'])) {
                    $out[] = $chunk['file'];
                }
            }
        }

        return $out;
    }
}
