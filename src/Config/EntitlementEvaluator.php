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

use Vtinnovations\Migrator\Transfer\ExchangeException;
use Vtinnovations\Migrator\Transfer\ExchangeResponseReader;

/**
 * Reads the stored entitlement, re-authenticates its exact bytes against the signed envelope (so a
 * hand-edited state file fails closed), enforces schema/project/exact-host binding, and maps the
 * authenticated record to an {@see EntitlementState} via the licence-model policy.
 *
 * Every protected feature boundary calls {@see self::evaluate()} and enforces on the returned
 * immutable {@see EntitlementState} server-side; UI visibility is never the authorization. There is
 * deliberately NO convenience `isLicensed()` shortcut here: a single mutable boolean gate would be
 * one edit away from unlocking every protected operation at once. If the exact-host intersection no
 * longer holds (e.g. the root-page domain changed), the instance reverts to unlicensed/default.
 */
final class EntitlementEvaluator
{
    public function __construct(
        private readonly EntitlementStore $store,
        private readonly ExchangeResponseReader $reader,
        private readonly HostInventory $inventory,
        private readonly HostNormalizer $normalizer,
        private readonly EntitlementModelPolicy $policy,
        private readonly string $projectSlug,
    ) {
    }

    /**
     * The full licence key and deterministic matched domain, ONLY when the current evaluation is
     * licensed (i.e. the stored record is cryptographically authentic). Returns null otherwise.
     * The key is read from the authenticated stored bytes — never from request input — and is the
     * sole source permitted for the once-per-session module-entry telemetry signal.
     *
     * @return array{key:string, domain:string}|null
     */
    public function authenticatedKeyAndDomain(?int $now = null): ?array
    {
        $state = $this->evaluate($now);

        if (!$state->isLicensed()) {
            return null;
        }

        $raw = $this->store->loadRaw();

        if (null === $raw) {
            return null;
        }

        $key = EntitlementRecord::fromBytes($raw['bytes'], $raw['envelope'])->key();

        if ('' === $key) {
            return null;
        }

        return ['key' => $key, 'domain' => $state->matchedDomain];
    }

    public function evaluate(?int $now = null): EntitlementState
    {
        $now ??= time();
        $raw = $this->store->loadRaw();

        if (null === $raw) {
            return EntitlementState::unlicensed('no_state');
        }

        // Re-run the full crypto pipeline over the stored bytes: this rejects any local tampering
        // of entitlement.json before the state can grant anything.
        try {
            $record = $this->reader->open(
                ['license_payload_b64' => base64_encode($raw['bytes']), 'integrity' => $raw['envelope']],
                $now,
            );
        } catch (ExchangeException $e) {
            return EntitlementState::invalid($e->category());
        }

        if (2 !== $record->schemaVersion()) {
            return EntitlementState::invalid('schema_version');
        }

        if (!hash_equals($this->projectSlug, $record->slug())) {
            return EntitlementState::invalid('slug_mismatch');
        }

        $domains = $record->domains();

        if (!$this->normalizer->isCanonicalSet($domains)) {
            return EntitlementState::invalid('domains_not_canonical');
        }

        if ($record->maxDomains() < 1) {
            return EntitlementState::invalid('bad_max_domains');
        }

        if (!\in_array($record->domain(), $domains, true)) {
            return EntitlementState::invalid('license_domain_not_member');
        }

        // Exact-host intersection with the trusted configured inventory must still hold.
        $intersection = $this->inventory->firstIntersection($domains);

        if (null === $intersection) {
            return EntitlementState::unlicensed('no_domain_intersection');
        }

        // Prefer the persisted matched domain when it is still a valid member and configured.
        $matched = $raw['matchedDomain'];

        if ('' === $matched || !\in_array($matched, $domains, true) || null === $this->inventory->firstIntersection([$matched])) {
            $matched = $intersection;
        }

        return $this->policy->evaluate($record, $now, $matched);
    }
}
