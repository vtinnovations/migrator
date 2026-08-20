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

use Vtinnovations\Migrator\Transfer\ExchangeClient;
use Vtinnovations\Migrator\Transfer\ExchangeException;
use Vtinnovations\Migrator\Transfer\ExchangeResponseReader;

/**
 * Orchestrates the write-side licence operations — activate, refresh, remove — as atomic
 * transactions. A complete signed package is fully verified BEFORE any persistence; a transport or
 * verification failure preserves the previously valid state and never fabricates a fallback.
 *
 * Domain binding is enforced here at request time: the sent verification domain must equal the
 * signed `license_domain`, which must be a member of the canonical `license_domains`, which must
 * intersect the trusted configured inventory. Rollback is rejected — a package cannot replace a
 * strictly newer stored version.
 */
final class EntitlementExchange
{
    public function __construct(
        private readonly ExchangeClient $client,
        private readonly ExchangeResponseReader $reader,
        private readonly EntitlementStore $store,
        private readonly EntitlementEvaluator $evaluator,
        private readonly HostInventory $inventory,
        private readonly HostNormalizer $normalizer,
        private readonly string $projectSlug,
    ) {
    }

    /**
     * First activation with a freshly entered key. Throws {@see ExchangeException} (safe category)
     * on any failure; the previous state, if any, is untouched.
     */
    public function activate(string $licenseKey, ?string $currentHost, ?int $now = null): EntitlementState
    {
        $licenseKey = trim($licenseKey);

        if ('' === $licenseKey) {
            throw new ExchangeException('empty_key');
        }

        $now ??= time();
        $domain = $this->requireVerificationDomain($currentHost);
        $response = $this->client->activate($licenseKey, $domain, $now);

        return $this->verifyAndPersist($response, $domain, null, $now);
    }

    /**
     * Administrator refresh using the stored key and current version. Preserves prior state on any
     * failure.
     */
    public function refresh(?string $currentHost, ?int $now = null): EntitlementState
    {
        $now ??= time();
        $raw = $this->store->loadRaw();

        if (null === $raw) {
            throw new ExchangeException('no_state');
        }

        $current = EntitlementRecord::fromBytes($raw['bytes'], $raw['envelope']);
        $key = $current->key();

        if ('' === $key) {
            throw new ExchangeException('no_stored_key');
        }

        $domain = $this->requireVerificationDomain($currentHost);
        $response = $this->client->refresh($key, $domain, $current->version(), $now);

        return $this->verifyAndPersist($response, $domain, $current->version(), $now);
    }

    /**
     * Apply a server-initiated push package (already request-authenticated by the updater endpoint).
     * The package must verify cryptographically, bind to this instance, and be STRICTLY newer than
     * the stored version. Returns the applied version.
     *
     * @param array<string, mixed> $response the updater body (carries license_payload_b64+integrity)
     */
    public function applyPush(array $response, string $bodyDomain, int $now): int
    {
        $state = $this->verifyAndPersist($response, $bodyDomain, null, $now, true);

        return $state->version;
    }

    /**
     * Authorised local removal: drop the authoritative state under lock so the instance reverts to
     * framework default immediately.
     */
    public function remove(): void
    {
        $this->store->withLock(function (): void {
            $this->store->remove();
        });
    }

    /**
     * @param array<string, mixed> $response
     */
    private function verifyAndPersist(array $response, string $sentDomain, ?int $baselineVersion, int $now, bool $strictNewer = false): EntitlementState
    {
        $record = $this->reader->open($response, $now); // throws ExchangeException on bad crypto

        if (2 !== $record->schemaVersion()) {
            throw new ExchangeException('schema_version');
        }

        if (!hash_equals($this->projectSlug, $record->slug())) {
            throw new ExchangeException('slug_mismatch');
        }

        // Exact request-domain equality with the signed operation host.
        if (!hash_equals($sentDomain, $record->domain())) {
            throw new ExchangeException('domain_mismatch');
        }

        $domains = $record->domains();

        // Reject a non-canonical signed set HERE, at the write boundary, not only at evaluation.
        // Both sides must agree: if the store accepted a payload the evaluator later refuses, the
        // activation would report success and the instance would still be unlicensed — the worst
        // possible outcome for an operator, because nothing points at the real cause.
        if (!$this->normalizer->isCanonicalSet($domains)) {
            throw new ExchangeException('domains_not_canonical');
        }

        // Informational allowance, but it must still be a signed positive integer. Note there is
        // deliberately no count($domains) <= maxDomains guard: the server intentionally lets an
        // existing binding survive an allowance being lowered, and enforcing it here would take a
        // legitimately licensed installation dark.
        if ($record->maxDomains() < 1) {
            throw new ExchangeException('bad_max_domains');
        }

        if (!\in_array($record->domain(), $domains, true)) {
            throw new ExchangeException('license_domain_not_member');
        }

        $intersection = $this->inventory->firstIntersection($domains);

        if (null === $intersection) {
            throw new ExchangeException('no_domain_intersection');
        }

        // Rollback prevention: never replace a strictly newer stored version.
        if (null !== $baselineVersion && $record->version() < $baselineVersion) {
            throw new ExchangeException('rollback_rejected');
        }

        $stored = $this->store->loadRaw();

        if (null !== $stored) {
            $previous = EntitlementRecord::fromBytes($stored['bytes'], $stored['envelope']);

            if ($record->version() < $previous->version()) {
                throw new ExchangeException('rollback_rejected');
            }

            // A push must be strictly newer — an equal version is either an exact retry (handled
            // idempotently upstream by the journal) or a forgery, never a fresh apply.
            if ($strictNewer && $record->version() <= $previous->version()) {
                throw new ExchangeException('not_newer');
            }
        }

        $result = $this->store->withLock(function () use ($record, $intersection): bool {
            $this->store->save($record->rawBytes, $record->envelope(), $intersection);

            return true;
        });

        if (true !== $result) {
            throw new ExchangeException('state_locked');
        }

        return $this->evaluator->evaluate($now);
    }

    private function requireVerificationDomain(?string $currentHost): string
    {
        $domain = $this->inventory->selectVerificationDomain($currentHost);

        if (null === $domain) {
            throw new ExchangeException('no_configured_domain');
        }

        return $domain;
    }
}
