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
 * Representation-only host normalization for exact-host licence binding.
 *
 * Normalization may lowercase, remove one trailing dot, remove an approved port, and convert an
 * IDN consistently to ASCII/Punycode. It must NEVER change hostname scope: no `www` stripping, no
 * reduction to a registrable/effective top-level domain, no alias/CNAME resolution, no suffix
 * matching. `example.com`, `www.example.com` and `shop.example.com` remain three distinct hosts.
 */
final class HostNormalizer
{
    /**
     * Normalize a single host, or return '' when the input is empty/unusable.
     */
    public function normalize(string $host): string
    {
        $host = trim($host);

        if ('' === $host) {
            return '';
        }

        // Drop a scheme and any path if a full URL slipped in.
        if (false !== ($pos = strpos($host, '://'))) {
            $host = substr($host, $pos + 3);
        }

        $host = explode('/', $host)[0];

        // Strip userinfo.
        if (false !== ($at = strrpos($host, '@'))) {
            $host = substr($host, $at + 1);
        }

        // Remove a port (but leave bracketed IPv6 literals intact).
        if (!str_starts_with($host, '[') && false !== ($colon = strrpos($host, ':'))) {
            $maybePort = substr($host, $colon + 1);

            if (1 === preg_match('/^\d{1,5}$/', $maybePort)) {
                $host = substr($host, 0, $colon);
            }
        }

        // Remove exactly one trailing dot.
        if (str_ends_with($host, '.')) {
            $host = substr($host, 0, -1);
        }

        $host = strtolower($host);

        // Consistent IDN → Punycode when intl is available; otherwise leave the label untouched.
        if ('' !== $host && \function_exists('idn_to_ascii') && preg_match('/[^\x20-\x7e]/', $host)) {
            $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (false !== $ascii && '' !== $ascii) {
                $host = strtolower($ascii);
            }
        }

        return $host;
    }

    /**
     * True when $domains is a signed domain set in canonical form: non-empty, and every entry an
     * exact lowercase hostname, free of wildcards, in strictly ascending bytewise order (which also
     * makes duplicates impossible).
     *
     * The list is validated exactly AS RECEIVED and is never re-sorted here. `vt-one/canonical-json-v1`
     * preserves array order, so the signature covers this list in the order it arrived: quietly
     * sorting it before the check would let a non-canonical payload pass as canonical.
     *
     * @param list<string> $domains
     */
    public function isCanonicalSet(array $domains): bool
    {
        if ([] === $domains) {
            return false;
        }

        $previous = null;

        foreach ($domains as $host) {
            if ('' === $host || str_contains($host, '*') || $host !== strtolower($host)) {
                return false;
            }

            // >= 0 catches both an unsorted pair and an exact duplicate.
            if (null !== $previous && strcmp($previous, $host) >= 0) {
                return false;
            }

            $previous = $host;
        }

        return true;
    }

    /**
     * Normalize a list of hosts, dropping empties and duplicates while preserving first-seen order.
     *
     * @param iterable<string> $hosts
     *
     * @return list<string>
     */
    public function normalizeAll(iterable $hosts): array
    {
        $out = [];

        foreach ($hosts as $host) {
            $normalized = $this->normalize((string) $host);

            if ('' !== $normalized && !\in_array($normalized, $out, true)) {
                $out[] = $normalized;
            }
        }

        return $out;
    }
}
