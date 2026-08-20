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

use Vtinnovations\Migrator\Config\ConfigStore;
use Vtinnovations\Migrator\Config\Paths;
use Vtinnovations\Migrator\Database\DatabaseDumper;
use Vtinnovations\Migrator\Job\Job;
use Vtinnovations\Migrator\Job\StepInterface;
use Vtinnovations\Migrator\Job\StepResult;
use Vtinnovations\Migrator\Manifest\Manifest;
use Vtinnovations\Migrator\Manifest\ManifestStore;

/**
 * Dumps the schema once, then each table's data into gzipped chunk files
 * db/data/{table}.{NNN}.sql.gz. Resumable: each slice writes complete chunk files and
 * persists its cursor (table index + keyset/offset position) so a killed request resumes
 * from the next row, never re-reading a finished chunk.
 */
final class DumpDatabaseStep implements StepInterface
{
    public function __construct(
        private readonly DatabaseDumper $dumper,
        private readonly ManifestStore $manifests,
        private readonly ConfigStore $config,
        private readonly Paths $paths,
    ) {
    }

    public function name(): string
    {
        return 'dump_database';
    }

    public function run(Job $job, float $deadline): StepResult
    {
        $manifest = $this->manifests->load($job->id) ?? new Manifest($job->id);
        $state = $job->payload['dump'] ?? null;

        if (null === $state) {
            $state = $this->begin($job, $manifest);
        }

        if ('schema' === $state['phase']) {
            $this->dumpSchema($job, $state);
            $state['phase'] = 'data';
            $job->payload['dump'] = $state;

            return StepResult::continueStep('Schema dumped, starting data.');
        }

        $tables = $state['tables'];
        $rowsPerChunk = (int) $this->config->get('db_rows_per_chunk', 2000);
        $maxBytes = (int) $this->config->get('db_max_insert_bytes', 1048576);

        while ($state['tableIndex'] < \count($tables) && microtime(true) < $deadline) {
            $table = $tables[$state['tableIndex']];
            $t = $this->ensureTableState($state, $table);

            $rows = null === $t['pk']
                ? $this->dumper->fetchOffset($table, $t['offset'], $rowsPerChunk)
                : $this->dumper->fetchKeyset($table, $t['pk'], $t['lastPk'], $rowsPerChunk);

            if ([] === $rows) {
                $this->finishTable($job, $manifest, $table, $t);
                ++$state['tableIndex'];
                $state['table'] = null;
                continue;
            }

            $meta = $t['meta'];
            $inserts = $this->dumper->buildInserts($table, $rows, $meta, $maxBytes);
            $chunk = $this->writeChunk($job->id, $table, $t['chunkIndex'], $inserts);

            $t['chunkIndex']++;
            $t['rowCount'] += \count($rows);
            $t['chunkHashes'][] = $chunk['sha256'];
            $t['chunks'][] = $chunk;

            if (null === $t['pk']) {
                $t['offset'] += \count($rows);
            } else {
                $last = end($rows);
                $t['lastPk'] = $last[$t['pk']] ?? null;
            }

            // Short final batch means the table is exhausted.
            if (\count($rows) < $rowsPerChunk) {
                $this->finishTable($job, $manifest, $table, $t);
                ++$state['tableIndex'];
                $state['table'] = null;
            } else {
                $state['table'] = $t;
            }
        }

        $job->payload['dump'] = $state;

        if ($state['tableIndex'] >= \count($tables)) {
            $this->manifests->save($job->id, $manifest);
            unset($job->payload['dump']);

            return StepResult::completeStep(sprintf('Database dump complete (%d tables).', \count($tables)));
        }

        $this->manifests->save($job->id, $manifest);

        return StepResult::continueStep(sprintf('Dumped %d/%d tables.', $state['tableIndex'], \count($tables)));
    }

    /**
     * @return array<string, mixed>
     */
    private function begin(Job $job, Manifest $manifest): array
    {
        $tables = $this->dumper->listTables();
        $manifest->set('database', ['tables' => [], 'schemaFile' => 'db/schema.sql']);
        $this->manifests->save($job->id, $manifest);

        return [
            'phase' => 'schema',
            'tables' => $tables,
            'tableIndex' => 0,
            'table' => null,
        ];
    }

    private function dumpSchema(Job $job, array $state): void
    {
        $dir = $this->paths->backupDir($job->id).'/db';

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create db dump dir.');
        }

        $handle = fopen($dir.'/schema.sql', 'wb');

        if (false === $handle) {
            throw new \RuntimeException('Could not open schema.sql for writing.');
        }

        try {
            fwrite($handle, "-- V&T Innovations Contao Migrator schema dump\n");
            fwrite($handle, "SET FOREIGN_KEY_CHECKS=0;\n\n");

            foreach ($state['tables'] as $table) {
                fwrite($handle, $this->dumper->createTableSql($table)."\n\n");
            }

            fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function ensureTableState(array &$state, string $table): array
    {
        if (\is_array($state['table'] ?? null)) {
            return $state['table'];
        }

        $state['table'] = [
            'name' => $table,
            'pk' => $this->dumper->pkColumn($table),
            'meta' => $this->dumper->columnMeta($table),
            'lastPk' => null,
            'offset' => 0,
            'chunkIndex' => 0,
            'rowCount' => 0,
            'chunkHashes' => [],
            'chunks' => [],
        ];

        return $state['table'];
    }

    /**
     * @return array{file:string, bytes:int, sha256:string}
     */
    private function writeChunk(string $jobId, string $table, int $index, array $statements): array
    {
        $dir = $this->paths->backupDir($jobId).'/db/data';

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new \RuntimeException('Could not create db/data dir.');
        }

        $rel = sprintf('db/data/%s.%03d.sql.gz', $this->safeName($table), $index);
        $abs = $this->paths->backupDir($jobId).'/'.$rel;

        $gz = gzopen($abs, 'wb9');

        if (false === $gz) {
            throw new \RuntimeException(sprintf('Could not open chunk "%s".', $abs));
        }

        try {
            foreach ($statements as $sql) {
                gzwrite($gz, $sql."\n");
            }
        } finally {
            gzclose($gz);
        }

        return [
            'file' => $rel,
            'bytes' => (int) filesize($abs),
            'sha256' => hash_file('sha256', $abs),
        ];
    }

    private function finishTable(Job $job, Manifest $manifest, string $table, array $t): void
    {
        $manifest->setTable($table, [
            'rowCount' => $t['rowCount'],
            'checksum' => hash('sha256', implode('', $t['chunkHashes'])),
            'chunks' => $t['chunks'],
            'pk' => $t['pk'],
        ]);
    }

    private function safeName(string $table): string
    {
        return preg_replace('/[^A-Za-z0-9_.-]/', '_', $table) ?? 'table';
    }
}
