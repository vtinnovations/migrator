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

namespace Vtinnovations\Migrator\Config;

/**
 * Minimal .env reader. Reads requested keys from a list of env files (later files win),
 * falling back to the live process environment. Only used to capture a handful of secrets
 * and DB coordinates — not a full dotenv parser.
 */
final class EnvFileReader
{
    public function __construct(private readonly string $projectDir)
    {
    }

    /**
     * @param list<string> $files relative to the project dir; later entries override earlier
     *
     * @return array<string, string>
     */
    public function readAll(array $files = ['.env', '.env.local']): array
    {
        $values = [];
        $root = rtrim($this->projectDir, '/\\');

        foreach ($files as $rel) {
            $path = $root.'/'.$rel;

            if (!is_file($path)) {
                continue;
            }

            foreach ($this->parse($path) as $k => $v) {
                $values[$k] = $v;
            }
        }

        return $values;
    }

    /**
     * @param list<string> $keys
     *
     * @return array<string, string> only keys that resolved to a non-empty value
     */
    public function get(array $keys, array $files = ['.env', '.env.local']): array
    {
        $all = $this->readAll($files);
        $out = [];

        foreach ($keys as $key) {
            $value = $all[$key] ?? (getenv($key) ?: ($_ENV[$key] ?? ($_SERVER[$key] ?? null)));

            if (\is_string($value) && '' !== $value) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private function parse(string $path): array
    {
        $out = [];
        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        foreach ($lines as $line) {
            $line = trim($line);

            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }

            if (str_starts_with($line, 'export ')) {
                $line = substr($line, 7);
            }

            $eq = strpos($line, '=');

            if (false === $eq) {
                continue;
            }

            $key = trim(substr($line, 0, $eq));
            $value = trim(substr($line, $eq + 1));

            if (\strlen($value) >= 2 && (($value[0] === '"' && str_ends_with($value, '"')) || ($value[0] === "'" && str_ends_with($value, "'")))) {
                $value = substr($value, 1, -1);
            }

            $out[$key] = $value;
        }

        return $out;
    }
}
