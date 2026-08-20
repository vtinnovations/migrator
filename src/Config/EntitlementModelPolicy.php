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
 * Entitlement state machine for the selected licence model. This product uses **Trial/Free and
 * Pro**: Trial/Free and Pro all require a signed activated licence — there is no anonymous Free
 * mode, no install-time trial, and no locally generated state. Trial start/duration/expiry are the
 * signed server dates; the client never invents or resets them.
 *
 * Transitions:
 *   - active trial/free/pro → licensed;
 *   - expired trial/free → unlicensed;
 *   - expired pro → the documented Free feature set only when the SAME authenticated payload signs
 *     `free_available = true`; otherwise unlicensed;
 *   - not-yet-valid or model-incompatible package → unlicensed/invalid.
 *
 * The record is already cryptographically authenticated when it reaches here; this class makes no
 * trust decisions, only maps authenticated dates/package to a {@see EntitlementState}.
 */
final class EntitlementModelPolicy
{
    private const MODEL = 'trial_free_pro';

    private const PKG_TRIAL = 'trial';
    private const PKG_FREE = 'free';
    private const PKG_PRO = 'pro';

    /** @var list<string> */
    private const ACCEPTED = [self::PKG_TRIAL, self::PKG_FREE, self::PKG_PRO];

    public function evaluate(EntitlementRecord $record, int $now, string $matchedDomain): EntitlementState
    {
        $package = $record->package();

        if (!\in_array($package, self::ACCEPTED, true)) {
            return EntitlementState::invalid('package_not_accepted');
        }

        $lifetime = $record->lifetime();
        $expiresAt = $record->expiresAt();
        $startsAt = $record->startsAt();
        $version = $record->version();

        // A non-lifetime package MUST carry a valid expiry after its start.
        if (!$lifetime) {
            if (null === $expiresAt) {
                return EntitlementState::invalid('missing_expiry');
            }

            if (null !== $startsAt && $expiresAt <= $startsAt) {
                return EntitlementState::invalid('expiry_before_start');
            }
        }

        // Not yet valid → treated as unlicensed/default until it starts.
        if (null !== $startsAt && $now < $startsAt) {
            return EntitlementState::unlicensed('not_yet_valid');
        }

        $active = $lifetime || (null !== $expiresAt && $now <= $expiresAt);

        if ($active) {
            $status = match ($package) {
                self::PKG_PRO => EntitlementState::STATUS_PRO,
                self::PKG_TRIAL => EntitlementState::STATUS_TRIAL,
                default => EntitlementState::STATUS_FREE,
            };

            return EntitlementState::licensed(
                $status,
                $package,
                $matchedDomain,
                $expiresAt,
                $lifetime,
                $version,
                $record->features(),
                $record->maskedKey(),
                $startsAt,
                $record->verifiedAt(),
            );
        }

        // Expired. Only an expired Pro with signed free_available retains the documented Free set.
        if (self::PKG_PRO === $package && $record->freeAvailable()) {
            return EntitlementState::licensed(
                EntitlementState::STATUS_PRO_FREE_FALLBACK,
                self::PKG_FREE,
                $matchedDomain,
                $expiresAt,
                false,
                $version,
                $record->features(),
                $record->maskedKey(),
                $startsAt,
                $record->verifiedAt(),
            );
        }

        return EntitlementState::expired($package, $matchedDomain, $expiresAt, $version);
    }
}
