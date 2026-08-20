<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Manifest;

/**
 * Deterministic UTF-8 JSON serialization used when a value must hash or verify identically on
 * two independent systems. Implements the fixed `vt-one/canonical-json-v1` rules that the
 * remote signer and this client must agree on byte-for-byte:
 *
 *   1. the top-level `signature` field is removed before serialization;
 *   2. object/map keys are recursively sorted in ascending bytewise (code-unit) order;
 *   3. list/array order is preserved exactly;
 *   4. output is UTF-8 JSON with no pretty printing, no escaped slashes, no escaped Unicode;
 *   5. scalar types are preserved exactly (false is not "false"; null is not 0).
 *
 * PHP's json_encode already emits the shortest form and preserves scalar typing; the only extra
 * work is the recursive key sort. Associative arrays serialize as JSON objects, list arrays as
 * JSON arrays — matching how a decoded licence document round-trips.
 */
final class CanonicalJson
{
    // Exactly the flags the V-T.ONE signer uses (CanonicalSerializer::encode): unescaped slashes +
    // unescaped Unicode, no pretty-print. No other flag — the signed payloads carry no floats, so
    // PRESERVE_ZERO_FRACTION would only risk diverging from the server's bytes.
    private const FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    /**
     * Serialize a decoded value to canonical bytes, stripping the top-level `signature` field.
     *
     * @param array<string, mixed> $value
     */
    public function encodeDocument(array $value): string
    {
        unset($value['signature']);

        return $this->encode($value);
    }

    /**
     * Serialize an arbitrary decoded value (no field stripping) to canonical bytes.
     */
    public function encode(mixed $value): string
    {
        $normalized = $this->sortRecursive($value);
        $json = json_encode($normalized, self::FLAGS);

        if (false === $json) {
            throw new \RuntimeException('Canonical JSON encoding failed: '.json_last_error_msg());
        }

        return $json;
    }

    /**
     * Recursively sort associative-array keys bytewise while leaving list arrays in place. A PHP
     * array is treated as a JSON object unless it is a sequential 0..n-1 list.
     */
    private function sortRecursive(mixed $value): mixed
    {
        if (!\is_array($value)) {
            return $value;
        }

        if ($this->isList($value)) {
            return array_map(fn ($item) => $this->sortRecursive($item), $value);
        }

        // ksort by bytewise key order. PHP string comparison in SORT_STRING is byte-oriented,
        // which is what the canonical rule requires for ASCII/UTF-8 keys.
        uksort($value, static fn (string|int $a, string|int $b): int => strcmp((string) $a, (string) $b));

        $out = [];

        foreach ($value as $key => $item) {
            $out[$key] = $this->sortRecursive($item);
        }

        return $out;
    }

    /**
     * @param array<mixed> $value
     */
    private function isList(array $value): bool
    {
        return array_is_list($value);
    }
}
