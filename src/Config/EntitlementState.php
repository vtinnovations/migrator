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
 * model and trusted time. This is the single shared input to every server-side feature gate; it is
 * NOT a mutable boolean that one edit could flip to unlock everything — each protected boundary
 * calls {@see self::isLicensed()} close to its own operation.
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

    /**
     * The last three arguments exist for the administrator surface alone: the
     * key already masked, and the two timestamps that are not part of any gate.
     * They travel on the state so the status block can show which licence is
     * installed without reading the stored key itself.
     *
     * @param list<string> $features
     */
    private function __construct(
        public readonly string $status,
        public readonly bool $licensed,
        public readonly string $package,
        public readonly string $matchedDomain,
        public readonly ?int $expiresAt,
        public readonly bool $lifetime,
        public readonly int $version,
        public readonly array $features,
        public readonly string $reason,
        public readonly string $maskedKey = '',
        public readonly ?int $startsAt = null,
        public readonly ?int $verifiedAt = null,
    ) {
    }

    public static function unlicensed(string $reason = ''): self
    {
        return new self(self::STATUS_UNLICENSED, false, '', '', null, false, 0, [], $reason);
    }

    /**
     * @param list<string> $features
     */
    public static function licensed(
        string $status,
        string $package,
        string $matchedDomain,
        ?int $expiresAt,
        bool $lifetime,
        int $version,
        array $features,
        string $maskedKey = '',
        ?int $startsAt = null,
        ?int $verifiedAt = null,
    ): self {
        return new self($status, true, $package, $matchedDomain, $expiresAt, $lifetime, $version, $features, '', $maskedKey, $startsAt, $verifiedAt);
    }

    /**
     * @param list<string> $features
     */
    public static function expired(string $package, string $matchedDomain, ?int $expiresAt, int $version): self
    {
        return new self(self::STATUS_EXPIRED, false, $package, $matchedDomain, $expiresAt, false, $version, [], 'expired');
    }

    public static function invalid(string $reason): self
    {
        return new self(self::STATUS_INVALID, false, '', '', null, false, 0, [], $reason);
    }

    public function isLicensed(): bool
    {
        return $this->licensed;
    }
}
