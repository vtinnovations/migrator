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

namespace Vtinnovations\Migrator\Database;

use Doctrine\DBAL\Connection;

/**
 * PHP-native database dump primitives — no mysqldump, no exec. Schema via SHOW CREATE
 * TABLE (MySQL) with a schema-manager fallback (SQLite harness). Data is read in keyset
 * batches by single-column PK where possible, LIMIT/OFFSET otherwise.
 *
 * Values are emitted VERBATIM (no URL rewriting at dump time — that happens at import so
 * it is repeatable and auditable). BINARY/BLOB columns become UNHEX('…') hex literals so
 * Contao DBAFS UUIDs (BINARY(16)) round-trip exactly; numeric columns are emitted raw;
 * everything else goes through Connection::quote().
 */
final class DatabaseDumper
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return list<string>
     */
    public function listTables(): array
    {
        return $this->connection->createSchemaManager()->listTableNames();
    }

    public function createTableSql(string $table): string
    {
        // MySQL/MariaDB: authoritative DDL incl. engine, charset, keys.
        try {
            $row = $this->connection->fetchAssociative('SHOW CREATE TABLE '.$this->connection->quoteIdentifier($table));

            if (\is_array($row)) {
                foreach (['Create Table', 'Create View'] as $key) {
                    if (isset($row[$key])) {
                        return (string) $row[$key].';';
                    }
                }
            }
        } catch (\Throwable) {
            // Fall through to the portable reconstruction below.
        }

        return $this->reconstructCreateSql($table);
    }

    /**
     * Single-column primary key name for keyset pagination, or null (→ OFFSET fallback).
     */
    public function pkColumn(string $table): ?string
    {
        try {
            $indexes = $this->connection->createSchemaManager()->listTableIndexes($table);

            if (isset($indexes['primary'])) {
                $cols = $indexes['primary']->getColumns();

                if (1 === \count($cols)) {
                    return $cols[0];
                }
            }
        } catch (\Throwable) {
            // ignore
        }

        return null;
    }

    /**
     * Ordered column metadata: name => ['binary'=>bool, 'numeric'=>bool].
     *
     * @return array<string, array{binary:bool, numeric:bool}>
     */
    public function columnMeta(string $table): array
    {
        $meta = [];

        foreach ($this->connection->createSchemaManager()->listTableColumns($table) as $column) {
            $type = $this->typeName($column->getType());

            $meta[$column->getName()] = [
                'binary' => \in_array($type, ['binary', 'blob', 'varbinary'], true),
                'numeric' => \in_array($type, ['integer', 'smallint', 'bigint', 'decimal', 'float', 'boolean'], true),
            ];
        }

        return $meta;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchKeyset(string $table, string $pk, int|string|null $afterPk, int $limit): array
    {
        $tbl = $this->connection->quoteIdentifier($table);
        $col = $this->connection->quoteIdentifier($pk);

        if (null === $afterPk) {
            return $this->connection->fetchAllAssociative("SELECT * FROM {$tbl} ORDER BY {$col} ASC LIMIT {$limit}");
        }

        return $this->connection->fetchAllAssociative(
            "SELECT * FROM {$tbl} WHERE {$col} > ? ORDER BY {$col} ASC LIMIT {$limit}",
            [$afterPk]
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function fetchOffset(string $table, int $offset, int $limit): array
    {
        $tbl = $this->connection->quoteIdentifier($table);

        return $this->connection->fetchAllAssociative("SELECT * FROM {$tbl} LIMIT {$limit} OFFSET {$offset}");
    }

    /**
     * Build one or more INSERT statements for the given rows, each capped at $maxBytes.
     *
     * @param list<array<string, mixed>>                            $rows
     * @param array<string, array{binary:bool, numeric:bool}>       $meta
     *
     * @return list<string>
     */
    public function buildInserts(string $table, array $rows, array $meta, int $maxBytes): array
    {
        if ([] === $rows) {
            return [];
        }

        $tbl = $this->connection->quoteIdentifier($table);
        $columns = array_keys($rows[0]);
        $colSql = implode(', ', array_map($this->connection->quoteIdentifier(...), $columns));
        $prefix = "INSERT INTO {$tbl} ({$colSql}) VALUES\n";

        $statements = [];
        $buffer = $prefix;
        $rowsInBuffer = 0;

        foreach ($rows as $row) {
            $tuple = '('.implode(', ', array_map(
                fn (string $c) => $this->literal($row[$c] ?? null, $meta[$c] ?? ['binary' => false, 'numeric' => false]),
                $columns
            )).')';

            $candidateLen = \strlen($buffer) + \strlen($tuple) + 2;

            if ($rowsInBuffer > 0 && $candidateLen > $maxBytes) {
                $statements[] = rtrim($buffer, ",\n").';';
                $buffer = $prefix;
                $rowsInBuffer = 0;
            }

            $buffer .= ($rowsInBuffer > 0 ? ",\n" : '').$tuple;
            ++$rowsInBuffer;
        }

        if ($rowsInBuffer > 0) {
            $statements[] = rtrim($buffer, ",\n").';';
        }

        return $statements;
    }

    /**
     * @param array{binary:bool, numeric:bool} $meta
     */
    private function literal(mixed $value, array $meta): string
    {
        if (null === $value) {
            return 'NULL';
        }

        if (\is_resource($value)) {
            $value = stream_get_contents($value);
            $value = false === $value ? '' : $value;
        }

        if ($meta['binary']) {
            return "UNHEX('".bin2hex((string) $value)."')";
        }

        if ($meta['numeric']) {
            if (\is_bool($value)) {
                return $value ? '1' : '0';
            }

            // Emit the numeric token verbatim; guard against anything non-numeric sneaking in.
            $s = (string) $value;

            return preg_match('/^-?\d+(\.\d+)?$/', $s) ? $s : $this->connection->quote($s);
        }

        return $this->connection->quote((string) $value);
    }

    private function typeName(mixed $type): string
    {
        $name = $type instanceof \Stringable ? (string) $type : (\is_object($type) ? $type::class : (string) $type);
        $name = strtolower($name);
        $name = (string) preg_replace('/^.*\\\\/', '', $name);
        $name = (string) preg_replace('/type$/', '', $name);

        return $name;
    }

    /**
     * Portable CREATE TABLE for platforms without SHOW CREATE TABLE (e.g. SQLite harness).
     */
    private function reconstructCreateSql(string $table): string
    {
        $sm = $this->connection->createSchemaManager();
        $tbl = $this->connection->quoteIdentifier($table);
        $defs = [];

        foreach ($sm->listTableColumns($table) as $column) {
            $defs[] = '  '.$this->connection->quoteIdentifier($column->getName())
                .' '.$this->typeName($column->getType())
                .($column->getNotnull() ? ' NOT NULL' : '');
        }

        return "CREATE TABLE {$tbl} (\n".implode(",\n", $defs)."\n);";
    }
}
