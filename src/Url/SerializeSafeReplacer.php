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

namespace Vtinnovations\Migrator\Url;

use Vtinnovations\Migrator\Util\SerializedData;

/**
 * Host search/replace that survives PHP-serialized blobs — the single most dangerous part
 * of any site move. A naive string REPLACE corrupts a serialized value the instant the
 * length changes, because the s:LEN: prefix no longer matches the payload.
 *
 * Strategy:
 *   - Plain strings: strtr() with all rule variants at once (longest match wins, no
 *     double-replacement).
 *   - Serialized arrays/scalars: unserialize (WITHOUT instantiating classes), deep-replace,
 *     re-serialize — PHP recomputes every length correctly.
 *   - Serialized data containing objects, or anything that fails to unserialize: a
 *     byte-safe regex fallback that recomputes each s:LEN: prefix after replacement.
 *
 * Replacement is host-oriented (scheme + host, protocol-relative, bare host) so DBAFS
 * UUIDs and {{file::uuid}} insert tags — which carry no host — are never touched.
 */
final class SerializeSafeReplacer
{
    /**
     * Build the strtr pair map from host rules.
     *
     * @param list<array{from:string, to:string}> $rules
     *
     * @return array<string, string>
     */
    public function buildPairs(array $rules): array
    {
        $pairs = [];

        foreach ($rules as $rule) {
            $from = trim($rule['from']);
            $to = trim($rule['to']);

            if ('' === $from || $from === $to) {
                continue;
            }

            // Most specific first is irrelevant for strtr (longest key wins), but we list
            // scheme-qualified variants so an http/https difference is also normalised.
            $pairs['https://'.$from] = 'https://'.$to;
            $pairs['http://'.$from] = 'http://'.$to;
            $pairs['//'.$from] = '//'.$to;
            $pairs[$from] = $to;
        }

        return $pairs;
    }

    /**
     * @param array<string, string> $pairs
     *
     * @return array{value:string, changed:bool}
     */
    public function replace(string $value, array $pairs): array
    {
        if ([] === $pairs || '' === $value) {
            return ['value' => $value, 'changed' => false];
        }

        if (!SerializedData::isSerialized($value)) {
            $new = strtr($value, $pairs);

            return ['value' => $new, 'changed' => $new !== $value];
        }

        // Serialized containing objects → never instantiate; use the byte-safe regex.
        if (preg_match('/(^|;|{|:)[OC]:\d+:/', $value)) {
            return $this->regexFallback($value, $pairs);
        }

        set_error_handler(static fn (): bool => true);

        try {
            $data = unserialize($value, ['allowed_classes' => false]);
        } finally {
            restore_error_handler();
        }

        if (false === $data && 'b:0;' !== $value) {
            return $this->regexFallback($value, $pairs);
        }

        $replaced = $this->deepReplace($data, $pairs);
        $new = serialize($replaced);

        return ['value' => $new, 'changed' => $new !== $value];
    }

    private function deepReplace(mixed $data, array $pairs): mixed
    {
        if (\is_string($data)) {
            return strtr($data, $pairs);
        }

        if (\is_array($data)) {
            $out = [];

            foreach ($data as $key => $val) {
                $newKey = \is_string($key) ? strtr($key, $pairs) : $key;
                $out[$newKey] = $this->deepReplace($val, $pairs);
            }

            return $out;
        }

        return $data;
    }

    /**
     * Byte-safe fallback: replace inside every s:LEN:"…"; token and recompute LEN. Used
     * only when the structured path cannot run (objects / corrupt serialization).
     *
     * @param array<string, string> $pairs
     *
     * @return array{value:string, changed:bool}
     */
    private function regexFallback(string $value, array $pairs): array
    {
        $new = preg_replace_callback(
            '/s:(\d+):"(.*?)";/s',
            static function (array $m) use ($pairs): string {
                $replaced = strtr($m[2], $pairs);

                return 's:'.\strlen($replaced).':"'.$replaced.'";';
            },
            $value
        );

        if (null === $new) {
            // preg failure (e.g. backtrack limit) — leave the value untouched, never corrupt.
            return ['value' => $value, 'changed' => false];
        }

        return ['value' => $new, 'changed' => $new !== $value];
    }
}
