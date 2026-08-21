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
 * Structured, immutable result of evaluating the stored entitlement against the selected licence
 * model and trusted time.
 *
 * This is the shared INPUT to the server-side feature gates, never the authorization itself. Note
 * there is deliberately no boolean field on this class any more: "licensed" is DERIVED from the
 * granted capability set, so there is no flag for one edit to flip. Each protected boundary asks
 * {@see self::allows()} for the one capability that boundary actually needs, close to its own
 * operation — a caller that only knows how to ask "am I licensed?" cannot reach a paid feature.
 *
 * Two capabilities exist. They double as the identifiers V-T.ONE may sign into `license_features`
 * to grant a capability the package alone would not carry:
 *
 *   - {@see self::CAP_ARCHIVE} — manual package work: export, import, recovery panel.
 *   - {@see self::CAP_DIRECT}  — server-to-server transfer: pairing mint, push, receive.
 */
final class EntitlementState
{
    public const STATUS_UNLICENSED = 'unlicensed';
    public const STATUS_TRIAL = 'trial';
    public const STATUS_FREE = 'free';
    public const STATUS_PRO = 'pro';
    public const STATUS_PRO_FREE_FALLBACK = 'pro_free_fallback';
    public const STATUS_EXPIRED = 'expired';
    public const STATUS_INVALID = 'invalid';

    /** Manual package work: export, import, and the standalone recovery panel. */
    public const CAP_ARCHIVE = 'transfer.archive';

    /** Direct server-to-server transfer: pairing mint, outbound push, inbound receive. */
    public const CAP_DIRECT = 'transfer.direct';

    /**
     * Every capability this product knows. A signed `license_features` entry outside this list is
     * ignored rather than trusted, so a future server-side id cannot accidentally mean something
     * here that it does not mean there.
     *
     * @var list<string>
     */
    public const CAPABILITIES = [self::CAP_ARCHIVE, self::CAP_DIRECT];

    /**
     * The last three arguments exist for the administrator surface alone: the key already masked,
     * and the two timestamps that are not part of any gate. They travel on the state so the status
     * block can show which licence is installed without reading the stored key itself.
     *
     * @param list<string> $features     the signed licence feature list, verbatim
     * @param list<string> $capabilities what this state actually grants (subset of CAPABILITIES)
     */
    private function __construct(
        public readonly string $status,
        public readonly string $package,
        public readonly string $matchedDomain,
        public readonly ?int $expiresAt,
        public readonly bool $lifetime,
        public readonly int $version,
        public readonly array $features,
        public readonly array $capabilities,
        public readonly string $reason,
        public readonly string $maskedKey = '',
        public readonly ?int $startsAt = null,
        public readonly ?int $verifiedAt = null,
    ) {
    }

    public static function unlicensed(string $reason = ''): self
    {
        return new self(self::STATUS_UNLICENSED, '', '', null, false, 0, [], [], $reason);
    }

    /**
     * @param list<string> $features
     * @param list<string> $capabilities must be non-empty — a "licensed" state that grants nothing
     *                                   is not a licensed state, and callers must not create one
     */
    public static function licensed(
        string $status,
        string $package,
        string $matchedDomain,
        ?int $expiresAt,
        bool $lifetime,
        int $version,
        array $features,
        array $capabilities,
        string $maskedKey = '',
        ?int $startsAt = null,
        ?int $verifiedAt = null,
    ): self {
        if ([] === $capabilities) {
            // Fail closed rather than mint a licensed-looking state with an empty grant set.
            return self::unlicensed('no_capability_granted');
        }

        return new self($status, $package, $matchedDomain, $expiresAt, $lifetime, $version, $features, $capabilities, '', $maskedKey, $startsAt, $verifiedAt);
    }

    /**
     * @param list<string> $features
     */
    public static function expired(string $package, string $matchedDomain, ?int $expiresAt, int $version, array $features = []): self
    {
        return new self(self::STATUS_EXPIRED, $package, $matchedDomain, $expiresAt, false, $version, $features, [], 'expired');
    }

    public static function invalid(string $reason): self
    {
        return new self(self::STATUS_INVALID, '', '', null, false, 0, [], [], $reason);
    }

    /**
     * True only when this state grants $capability. An empty or unknown capability name never
     * matches, so a boundary that forgets to name one is refused rather than waved through.
     */
    public function allows(string $capability): bool
    {
        if ('' === $capability) {
            return false;
        }

        return \in_array($capability, $this->capabilities, true);
    }

    /**
     * Whether this installation holds a valid signed licence granting anything at all. Use it for
     * status display and for the module-entry telemetry signal — never as a feature gate, because
     * it cannot tell the Free tier from the paid one. Feature gates call {@see self::allows()}.
     */
    public function isLicensed(): bool
    {
        return [] !== $this->capabilities;
    }
}
