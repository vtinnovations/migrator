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

namespace Vtinnovations\Migrator\Tests\Config;

use PHPUnit\Framework\TestCase;
use Vtinnovations\Migrator\Config\EntitlementModelPolicy;
use Vtinnovations\Migrator\Config\EntitlementState;
use Vtinnovations\Migrator\Tests\TestKit;

/**
 * Selected licence model: Trial/Free and Pro. Every tier requires a signed activated licence — the
 * policy only maps ALREADY authenticated dates/packages to a state, and can never create one.
 */
final class EntitlementModelPolicyTest extends TestCase
{
    use TestKit;

    private EntitlementModelPolicy $policy;

    private const NOW = 1800000000;

    protected function setUp(): void
    {
        $this->policy = new EntitlementModelPolicy();
    }

    public function testActiveProTrialAndFreeAreLicensed(): void
    {
        $expectations = [
            'pro' => EntitlementState::STATUS_PRO,
            'trial' => EntitlementState::STATUS_TRIAL,
            'free' => EntitlementState::STATUS_FREE,
        ];

        foreach ($expectations as $package => $status) {
            $state = $this->policy->evaluate(
                $this->record(['license_package' => $package, 'license_expires_at' => self::NOW + 86400]),
                self::NOW,
                'example.com',
            );

            self::assertTrue($state->isLicensed(), $package);
            self::assertSame($status, $state->status);
            self::assertSame('example.com', $state->matchedDomain);
        }
    }

    public function testPackageOutsideTheModelAllowlistIsInvalid(): void
    {
        foreach (['enterprise', 'lifetime', '', 'PRO'] as $package) {
            $state = $this->policy->evaluate($this->record(['license_package' => $package]), self::NOW, 'example.com');

            self::assertFalse($state->isLicensed(), $package);
            self::assertSame(EntitlementState::STATUS_INVALID, $state->status);
        }
    }

    public function testNonLifetimePackageWithoutExpiryIsRejected(): void
    {
        $state = $this->policy->evaluate(
            $this->record(['license_lifetime' => false, 'license_expires_at' => null]),
            self::NOW,
            'example.com',
        );

        self::assertSame(EntitlementState::STATUS_INVALID, $state->status);
        self::assertSame('missing_expiry', $state->reason);
    }

    public function testExpiryBeforeStartIsRejected(): void
    {
        $state = $this->policy->evaluate(
            $this->record(['license_starts_at' => self::NOW, 'license_expires_at' => self::NOW - 1]),
            self::NOW,
            'example.com',
        );

        self::assertSame('expiry_before_start', $state->reason);
    }

    public function testNotYetValidPackageIsUnlicensedUntilItStarts(): void
    {
        $state = $this->policy->evaluate(
            $this->record(['license_starts_at' => self::NOW + 10, 'license_expires_at' => self::NOW + 86400]),
            self::NOW,
            'example.com',
        );

        self::assertFalse($state->isLicensed());
        self::assertSame('not_yet_valid', $state->reason);
    }

    public function testSignedLifetimePackageNeedsNoExpiry(): void
    {
        $state = $this->policy->evaluate(
            $this->record(['license_lifetime' => true, 'license_expires_at' => null, 'license_package' => 'free']),
            self::NOW,
            'example.com',
        );

        self::assertTrue($state->isLicensed());
        self::assertTrue($state->lifetime);
    }

    public function testExpiredTrialOrFreeBecomesUnlicensedWithNoFallback(): void
    {
        foreach (['trial', 'free'] as $package) {
            $state = $this->policy->evaluate(
                $this->record([
                    'license_package' => $package,
                    'license_expires_at' => self::NOW - 1,
                    'free_available' => true,
                ]),
                self::NOW,
                'example.com',
            );

            self::assertFalse($state->isLicensed(), $package.' must not fall back');
            self::assertSame(EntitlementState::STATUS_EXPIRED, $state->status);
        }
    }

    public function testExpiredProKeepsTheFreeSetOnlyWhenTheSignedPayloadAllowsIt(): void
    {
        $withFallback = $this->policy->evaluate(
            $this->record(['license_package' => 'pro', 'license_expires_at' => self::NOW - 1, 'free_available' => true]),
            self::NOW,
            'example.com',
        );

        self::assertTrue($withFallback->isLicensed());
        self::assertSame(EntitlementState::STATUS_PRO_FREE_FALLBACK, $withFallback->status);
        self::assertSame('free', $withFallback->package);
        self::assertSame('example.com', $withFallback->matchedDomain, 'fallback stays bound to the same domain');

        $withoutFallback = $this->policy->evaluate(
            $this->record(['license_package' => 'pro', 'license_expires_at' => self::NOW - 1, 'free_available' => false]),
            self::NOW,
            'example.com',
        );

        self::assertFalse($withoutFallback->isLicensed());
        self::assertSame(EntitlementState::STATUS_EXPIRED, $withoutFallback->status);
    }

    public function testExpiryIsDrivenBySignedDatesOnly(): void
    {
        $record = $this->record(['license_package' => 'trial', 'license_expires_at' => self::NOW + 60]);

        self::assertTrue($this->policy->evaluate($record, self::NOW, 'example.com')->isLicensed());
        self::assertFalse($this->policy->evaluate($record, self::NOW + 61, 'example.com')->isLicensed());
    }
}
