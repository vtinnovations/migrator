<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Job\Step;

use Doctrine\DBAL\Connection;
use Vtinnovations\Migrator\Config\Paths;
use Vtinnovations\Migrator\Job\Job;
use Vtinnovations\Migrator\Job\StepInterface;
use Vtinnovations\Migrator\Job\StepResult;
use Vtinnovations\Migrator\Manifest\ManifestStore;
use Vtinnovations\Migrator\Url\SerializeSafeReplacer;

/**
 * Rewrites the source host to the destination host across every column the preflight scan
 * flagged, routing each value through the serialize-safe replacer so PHP-serialized blobs
 * keep valid s:LEN: prefixes.
 *
 * Resumable per candidate column (payload['urlReplace']). Columns with a primary key are
 * walked by keyset so a killed request resumes mid-table; pk-less tables are done in one
 * pass over their distinct matching values.
 */
final class UrlReplaceStep implements StepInterface
{
    private const BATCH = 500;

    public function __construct(
        private readonly Connection $connection,
        private readonly SerializeSafeReplacer $replacer,
        private readonly ManifestStore $manifests,
        private readonly Paths $paths,
    ) {
    }

    public function name(): string
    {
        return 'url_replace';
    }

    public function run(Job $job, float $deadline): StepResult
    {
        $staging = (string) ($job->payload['staging'] ?? $this->paths->stagingDir($job->id));
        $manifest = $this->manifests->loadPath($staging.'/manifest.json');

        if (null === $manifest) {
            return StepResult::fail('Manifest missing for URL replacement.');
        }

        $candidates = $manifest->get('urlReplacements')['candidates'] ?? [];

        if ([] === $candidates) {
            return StepResult::completeStep('No URL candidates — nothing to rewrite.');
        }

        $urlMap = $job->meta['urlMap'] ?? [];

        if ([] === $urlMap) {
            return StepResult::pause('URL replacement needs an operator-confirmed host map.');
        }

        $pairs = $this->replacer->buildPairs($urlMap);

        if ([] === $pairs) {
            return StepResult::completeStep('Host map is a no-op — nothing to rewrite.');
        }

        $state = $job->payload['urlReplace'] ?? ['index' => 0, 'lastPk' => null, 'changed' => 0];
        $total = \count($candidates);
        $index = (int) $state['index'];
        $changed = (int) $state['changed'];

        while ($index < $total) {
            $cand = $candidates[$index];
            $pkCols = $cand['pk'] ?? [];

            if ([] !== $pkCols) {
                $res = $this->walkKeyset($cand, $pkCols, $pairs, $state['lastPk'], $deadline);
                $changed += $res['changed'];

                if (!$res['done']) {
                    $state = ['index' => $index, 'lastPk' => $res['lastPk'], 'changed' => $changed];
                    $job->payload['urlReplace'] = $state;

                    return StepResult::continueStep(sprintf('Rewriting %s.%s …', $cand['table'], $cand['column']));
                }
            } else {
                $changed += $this->replaceNoPk($cand, $pairs);
            }

            ++$index;
            $state = ['index' => $index, 'lastPk' => null, 'changed' => $changed];
            $job->payload['urlReplace'] = $state;

            if (microtime(true) >= $deadline) {
                return StepResult::continueStep(sprintf('URL rewrite %d/%d columns.', $index, $total));
            }
        }

        unset($job->payload['urlReplace']);

        return StepResult::completeStep(sprintf('URL rewrite complete (%d value(s) changed).', $changed));
    }

    /**
     * Keyset walk over one column using its first primary-key column as the cursor.
     *
     * @param array<string, mixed> $cand
     * @param list<string>         $pkCols
     * @param array<string,string> $pairs
     *
     * @return array{done:bool, lastPk:mixed, changed:int}
     */
    private function walkKeyset(array $cand, array $pkCols, array $pairs, mixed $lastPk, float $deadline): array
    {
        $tbl = $this->connection->quoteIdentifier($cand['table']);
        $col = $this->connection->quoteIdentifier($cand['column']);
        $cursor = $this->connection->quoteIdentifier($pkCols[0]);
        $like = '%'.$this->escapeLike((string) $cand['host']).'%';
        $changed = 0;

        do {
            $where = "{$col} LIKE ? ESCAPE '!'";
            $params = [$like];

            if (null !== $lastPk) {
                $where .= " AND {$cursor} > ?";
                $params[] = $lastPk;
            }

            $select = implode(', ', array_map(fn (string $c): string => $this->connection->quoteIdentifier($c), $pkCols));
            $rows = $this->connection->fetchAllAssociative(
                "SELECT {$select}, {$col} FROM {$tbl} WHERE {$where} ORDER BY {$cursor} ASC LIMIT ".self::BATCH,
                $params
            );

            if ([] === $rows) {
                return ['done' => true, 'lastPk' => $lastPk, 'changed' => $changed];
            }

            foreach ($rows as $row) {
                $lastPk = $row[$pkCols[0]];
                $result = $this->replacer->replace((string) $row[$cand['column']], $pairs);

                if ($result['changed']) {
                    $this->updateRow($cand['table'], $cand['column'], $result['value'], $pkCols, $row);
                    ++$changed;
                }
            }

            if (\count($rows) < self::BATCH) {
                return ['done' => true, 'lastPk' => $lastPk, 'changed' => $changed];
            }
        } while (microtime(true) < $deadline);

        return ['done' => false, 'lastPk' => $lastPk, 'changed' => $changed];
    }

    /**
     * Single-pass rewrite for tables with no primary key: replace each distinct matching value.
     *
     * @param array<string, mixed> $cand
     * @param array<string,string> $pairs
     */
    private function replaceNoPk(array $cand, array $pairs): int
    {
        $tbl = $this->connection->quoteIdentifier($cand['table']);
        $col = $this->connection->quoteIdentifier($cand['column']);
        $like = '%'.$this->escapeLike((string) $cand['host']).'%';
        $changed = 0;

        $values = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT {$col} FROM {$tbl} WHERE {$col} LIKE ? ESCAPE '!'",
            [$like]
        );

        foreach ($values as $old) {
            if (!\is_string($old)) {
                continue;
            }

            $result = $this->replacer->replace($old, $pairs);

            if ($result['changed']) {
                $this->connection->executeStatement(
                    "UPDATE {$tbl} SET {$col} = ? WHERE {$col} = ?",
                    [$result['value'], $old]
                );
                ++$changed;
            }
        }

        return $changed;
    }

    /**
     * @param list<string>         $pkCols
     * @param array<string, mixed> $row
     */
    private function updateRow(string $table, string $column, string $value, array $pkCols, array $row): void
    {
        $tbl = $this->connection->quoteIdentifier($table);
        $col = $this->connection->quoteIdentifier($column);

        $where = [];
        $params = [$value];

        foreach ($pkCols as $pk) {
            $where[] = $this->connection->quoteIdentifier($pk).' = ?';
            $params[] = $row[$pk];
        }

        $this->connection->executeStatement(
            "UPDATE {$tbl} SET {$col} = ? WHERE ".implode(' AND ', $where),
            $params
        );
    }

    private function escapeLike(string $needle): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle);
    }
}
