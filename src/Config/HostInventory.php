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

use Vtinnovations\Migrator\Preflight\RootPageScanner;

/**
 * Trusted, normalized configured-domain inventory for the global Contao instance, derived from
 * root-page configuration (never from arbitrary Host/Forwarded headers).
 *
 * For this global-module profile the inventory is the union of the hosts configured on every
 * Contao root page. Activation requires at least one exact normalized host present in BOTH this
 * inventory and the signed `license_domains` set — an exact set intersection, never suffix,
 * wildcard, registrable-domain, apex/`www`, parent or subdomain equivalence.
 */
final class HostInventory
{
    public function __construct(
        private readonly RootPageScanner $rootPages,
        private readonly HostNormalizer $normalizer,
    ) {
    }

    /**
     * @return list<string> normalized configured hosts, first-seen order preserved
     */
    public function inventory(): array
    {
        return $this->normalizer->normalizeAll($this->rootPages->scan()['hosts']);
    }

    /**
     * Deterministically choose the host to send in the verification request:
     *   1. the current trusted host when it belongs to the inventory;
     *   2. otherwise the primary (first) configured host;
     *   3. null when no trusted configured domain exists (activation must refuse).
     */
    public function selectVerificationDomain(?string $currentHost): ?string
    {
        $inventory = $this->inventory();

        if ([] === $inventory) {
            return null;
        }

        $current = $this->normalizer->normalize((string) $currentHost);

        if ('' !== $current && \in_array($current, $inventory, true)) {
            return $current;
        }

        return $inventory[0];
    }

    /**
     * First exact host present in both the inventory and $signedDomains, or null when the sets do
     * not intersect. $signedDomains must already be normalized/canonical from the signed payload.
     *
     * @param list<string> $signedDomains
     */
    public function firstIntersection(array $signedDomains): ?string
    {
        $signed = array_flip($signedDomains);

        foreach ($this->inventory() as $host) {
            if (isset($signed[$host])) {
                return $host;
            }
        }

        return null;
    }
}
