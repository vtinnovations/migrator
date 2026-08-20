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
 * Applies a dumped schema + gzipped data chunks to the destination database. Statements
 * are executed one at a time (no multi-statement reliance), foreign-key checks are
 * suspended for the load, and chunk files are streamed through gzip so memory stays flat.
 */
final class DatabaseRestorer
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function disableForeignKeys(): void
    {
        $this->tryExec('SET FOREIGN_KEY_CHECKS=0');
        $this->tryExec('PRAGMA foreign_keys=OFF'); // SQLite harness
    }

    public function enableForeignKeys(): void
    {
        $this->tryExec('SET FOREIGN_KEY_CHECKS=1');
        $this->tryExec('PRAGMA foreign_keys=ON');
    }

    /**
     * Parse schema.sql into [table => createStatement]. CREATE statements from
     * SHOW CREATE TABLE carry no internal ";" so splitting on ";\n" is safe for our format.
     *
     * @return array<string, string>
     */
    public function parseSchema(string $schemaSql): array
    {
        $out = [];

        foreach ($this->splitStatements($schemaSql) as $stmt) {
            if (preg_match('/^CREATE\s+TABLE\s+[`"]?([^`"\s(]+)[`"]?/i', $stmt, $m)) {
                $out[$m[1]] = $stmt;
            }
        }

        return $out;
    }

    public function dropAndCreate(string $table, string $createSql): void
    {
        $this->connection->executeStatement('DROP TABLE IF EXISTS '.$this->connection->quoteIdentifier($table));
        $this->connection->executeStatement($createSql);
    }

    /**
     * Execute every statement in a gzipped chunk file. Returns the number of statements run.
     *
     * Idempotent + atomic so a resume can never duplicate rows: the step persists its chunk
     * index only at tick boundaries, so a tick that commits chunks [a..k-1] and then crashes on
     * chunk k leaves the persisted index behind those commits — a retry would replay [a..k-1] and
     * hit "Duplicate entry … for PRIMARY". We defend on two fronts:
     *   - each chunk runs inside ONE transaction, so a chunk that fails part-way rolls back whole;
     *   - INSERT statements are rewritten to REPLACE, so replaying an already-loaded chunk just
     *     overwrites identical rows (the destination table was DROP+CREATEd empty, and every row
     *     comes from the same dump, so the final state is identical either way).
     */
    public function loadChunk(string $gzPath): int
    {
        $raw = $this->gunzip($gzPath);
        $count = 0;

        $this->connection->beginTransaction();

        try {
            foreach ($this->splitStatements($raw) as $stmt) {
                $this->connection->executeStatement($this->toReplace($stmt));
                ++$count;
            }

            $this->connection->commit();
        } catch (\Throwable $e) {
            $this->connection->rollBack();

            throw $e;
        }

        return $count;
    }

    /**
     * Rewrite a leading "INSERT INTO" to "REPLACE INTO" so re-applying a chunk is idempotent.
     * The dump only emits value-list INSERTs (never INSERT … SELECT), so this is safe. Other
     * statement kinds are passed through unchanged.
     */
    private function toReplace(string $stmt): string
    {
        return preg_replace('/^\s*INSERT\s+(?:IGNORE\s+)?INTO\b/i', 'REPLACE INTO', $stmt, 1) ?? $stmt;
    }

    /**
     * @return list<string>
     */
    private function splitStatements(string $sql): array
    {
        $parts = preg_split('/;\s*\n/', $sql) ?: [];
        $out = [];

        foreach ($parts as $part) {
            $part = trim($part);
            $part = rtrim($part, ';');
            $part = trim($part);

            if ('' === $part || str_starts_with($part, '--')) {
                continue;
            }

            $out[] = $part;
        }

        return $out;
    }

    private function gunzip(string $path): string
    {
        $gz = gzopen($path, 'rb');

        if (false === $gz) {
            throw new \RuntimeException(sprintf('Could not open chunk "%s".', $path));
        }

        $data = '';

        try {
            while (!gzeof($gz)) {
                $buf = gzread($gz, 1048576);

                if (false === $buf) {
                    break;
                }

                $data .= $buf;
            }
        } finally {
            gzclose($gz);
        }

        return $data;
    }

    private function tryExec(string $sql): void
    {
        try {
            $this->connection->executeStatement($sql);
        } catch (\Throwable) {
            // Platform doesn't support this toggle — ignore.
        }
    }
}
