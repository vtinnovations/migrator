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

namespace Vtinnovations\Migrator\Preflight;

use Doctrine\DBAL\Connection;
use Vtinnovations\Migrator\Util\SerializedData;

/**
 * Finds where the source host appears in the database so the import confirm screen can
 * show the operator exactly which columns will be rewritten — and crucially flags columns
 * whose matches include serialized blobs (those must go through the serialize-safe
 * replacer, never a plain REPLACE).
 *
 * Works off the live schema via createSchemaManager() so it is portable to the SQLite
 * test harness. LIKE uses ESCAPE '!' because SQLite and MySQL disagree on backslash in
 * string literals; the needle's ! % _ are escaped accordingly.
 */
final class UrlCandidateScanner
{
    /** How many matched values to sample per column when checking for serialized data. */
    private const SERIALIZED_SAMPLE = 200;

    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * Ordered list of textual columns (table.column) keyed numerically so a scan can be
     * resumed from an integer offset across time-budget slices.
     *
     * @return list<array{table:string, column:string, pk:list<string>}>
     */
    public function textColumns(): array
    {
        $sm = $this->connection->createSchemaManager();
        $out = [];

        foreach ($sm->listTableNames() as $table) {
            $pk = [];

            try {
                $index = $sm->listTableIndexes($table);

                if (isset($index['primary'])) {
                    $pk = $index['primary']->getColumns();
                }
            } catch (\Throwable) {
                $pk = [];
            }

            foreach ($sm->listTableColumns($table) as $column) {
                if (!$this->isTextual($column->getType())) {
                    continue;
                }

                $out[] = [
                    'table' => $table,
                    'column' => $column->getName(),
                    'pk' => $pk,
                ];
            }
        }

        return $out;
    }

    /**
     * Count rows where $column contains $needle, and report whether any matched value is
     * a serialized blob.
     *
     * @return array{count:int, serialized:bool}
     */
    public function inspectColumn(string $table, string $column, string $needle): array
    {
        $tbl = $this->connection->quoteIdentifier($table);
        $col = $this->connection->quoteIdentifier($column);
        $like = '%'.$this->escapeLike($needle).'%';

        try {
            $count = (int) $this->connection->fetchOne(
                "SELECT COUNT(*) FROM {$tbl} WHERE {$col} LIKE ? ESCAPE '!'",
                [$like]
            );
        } catch (\Throwable) {
            return ['count' => 0, 'serialized' => false];
        }

        if (0 === $count) {
            return ['count' => 0, 'serialized' => false];
        }

        $serialized = false;

        try {
            $sample = $this->connection->fetchFirstColumn(
                "SELECT {$col} FROM {$tbl} WHERE {$col} LIKE ? ESCAPE '!' LIMIT ".self::SERIALIZED_SAMPLE,
                [$like]
            );

            foreach ($sample as $value) {
                if (\is_string($value) && SerializedData::isSerialized($value)) {
                    $serialized = true;
                    break;
                }
            }
        } catch (\Throwable) {
            // Counting succeeded; treat serialized as unknown-safe (false) on sample failure.
        }

        return ['count' => $count, 'serialized' => $serialized];
    }

    private function isTextual(mixed $type): bool
    {
        // DBAL 4 reports class-based types; normalise to a short lowercase name.
        $name = $type instanceof \Stringable ? (string) $type : (\is_object($type) ? $type::class : (string) $type);
        $name = strtolower($name);
        $name = (string) preg_replace('/^.*\\\\/', '', $name); // strip namespace
        $name = (string) preg_replace('/type$/', '', $name);   // StringType -> string

        return \in_array($name, ['string', 'text', 'ascii', 'asciistring', 'guid'], true);
    }

    /**
     * Escape LIKE wildcards and the chosen escape char so the needle matches literally.
     */
    private function escapeLike(string $needle): string
    {
        return str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $needle);
    }
}
