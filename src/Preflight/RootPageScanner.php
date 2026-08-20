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

/**
 * Reads Contao root pages (tl_page where type='root') to derive the source host(s) for
 * URL replacement and to flag roots with an empty dns column — those need the operator
 * to supply the old URL manually during the import confirm screen.
 */
final class RootPageScanner
{
    public function __construct(private readonly Connection $connection)
    {
    }

    /**
     * @return array{roots: list<array{id:int,dns:string,useSSL:bool,language:string,fallback:bool}>, hasEmptyDns: bool, hosts: list<string>}
     */
    public function scan(): array
    {
        $schema = $this->connection->createSchemaManager();

        if (!$schema->tablesExist(['tl_page'])) {
            return ['roots' => [], 'hasEmptyDns' => false, 'hosts' => []];
        }

        $rows = $this->connection->fetchAllAssociative(
            "SELECT id, dns, useSSL, language, fallback FROM tl_page WHERE type = 'root'"
        );

        $roots = [];
        $hosts = [];
        $hasEmptyDns = false;

        foreach ($rows as $row) {
            $dns = trim((string) ($row['dns'] ?? ''));

            if ('' === $dns) {
                $hasEmptyDns = true;
            } elseif (!\in_array($dns, $hosts, true)) {
                $hosts[] = $dns;
            }

            $roots[] = [
                'id' => (int) $row['id'],
                'dns' => $dns,
                'useSSL' => (bool) ($row['useSSL'] ?? false),
                'language' => (string) ($row['language'] ?? ''),
                'fallback' => (bool) ($row['fallback'] ?? false),
            ];
        }

        return ['roots' => $roots, 'hasEmptyDns' => $hasEmptyDns, 'hosts' => $hosts];
    }
}
