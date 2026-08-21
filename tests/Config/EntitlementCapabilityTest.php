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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Vtinnovations\Migrator\Config\EntitlementModelPolicy;
use Vtinnovations\Migrator\Config\EntitlementState;
use Vtinnovations\Migrator\Tests\TestKit;

/**
 * The paid boundary of this product.
 *
 * Free feature set = export, import, recovery ({@see EntitlementState::CAP_ARCHIVE}).
 * Paid            = direct server-to-server transfer ({@see EntitlementState::CAP_DIRECT}).
 *
 * These tests pin the grant matrix in both directions: what each tier must be able to do, and —
 * more important commercially — what it must NOT. A single wrong grant here gives the paid feature
 * away to every Free licence without any other test noticing.
 */
final class EntitlementCapabilityTest extends TestCase
{
    use TestKit;

    private EntitlementModelPolicy $policy;

    private const NOW = 1800000000;

    protected function setUp(): void
    {
        $this->policy = new EntitlementModelPolicy();
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function activePackages(): array
    {
        return [
            'pro includes direct transfer' => ['pro', true],
            'trial includes direct transfer' => ['trial', true],
            'free does not include direct transfer' => ['free', false],
        ];
    }

    #[DataProvider('activePackages')]
    public function testActivePackageGrant(string $package, bool $expectDirect): void
    {
        $state = $this->activeState($package);

        self::assertTrue($state->isLicensed(), $package.' must be licensed');
        self::assertTrue(
            $state->allows(EntitlementState::CAP_ARCHIVE),
            'export/import/recovery is the Free feature set and belongs to every tier',
        );
        self::assertSame($expectDirect, $state->allows(EntitlementState::CAP_DIRECT));
    }

    /**
     * A trial exists to evaluate the paid capability, so it carries it — and loses it on expiry
     * like any other time-limited package.
     */
    public function testExpiredTrialGrantsNothingAtAll(): void
    {
        $state = $this->policy->evaluate(
            $this->record([
                'license_package' => 'trial',
                'license_starts_at' => self::NOW - 172800,
                'license_expires_at' => self::NOW - 60,
            ]),
            self::NOW,
            'example.com',
        );

        self::assertFalse($state->isLicensed());
        self::assertSame([], $state->capabilities);
        self::assertFalse($state->allows(EntitlementState::CAP_ARCHIVE));
        self::assertFalse($state->allows(EntitlementState::CAP_DIRECT));
    }

    /**
     * The documented Free feature set, and only that. The fallback is a Free licence in all but
     * name, so keeping direct transfer alive past a Pro expiry would make the expiry meaningless.
     */
    public function testExpiredProFallbackKeepsArchiveButLosesDirect(): void
    {
        $state = $this->policy->evaluate(
            $this->record([
                'license_package' => 'pro',
                'license_starts_at' => self::NOW - 172800,
                'license_expires_at' => self::NOW - 60,
                'free_available' => true,
            ]),
            self::NOW,
            'example.com',
        );

        self::assertSame(EntitlementState::STATUS_PRO_FREE_FALLBACK, $state->status);
        self::assertTrue($state->isLicensed());
        self::assertTrue($state->allows(EntitlementState::CAP_ARCHIVE));
        self::assertFalse($state->allows(EntitlementState::CAP_DIRECT), 'an expired Pro must not keep the paid capability');
    }

    public function testExpiredProWithoutFallbackGrantsNothing(): void
    {
        $state = $this->policy->evaluate(
            $this->record([
                'license_package' => 'pro',
                'license_starts_at' => self::NOW - 172800,
                'license_expires_at' => self::NOW - 60,
                'free_available' => false,
            ]),
            self::NOW,
            'example.com',
        );

        self::assertFalse($state->isLicensed());
        self::assertSame([], $state->capabilities);
    }

    /**
     * The additive override: V-T.ONE can sell direct transfer onto a free package by signing the
     * capability id into license_features, without inventing a new package value.
     */
    public function testSignedFeatureAddsDirectTransferToAFreePackage(): void
    {
        $state = $this->activeState('free', [EntitlementState::CAP_DIRECT]);

        self::assertSame(EntitlementState::STATUS_FREE, $state->status);
        self::assertTrue($state->allows(EntitlementState::CAP_ARCHIVE));
        self::assertTrue($state->allows(EntitlementState::CAP_DIRECT));
    }

    /**
     * Additive only. An empty or unrecognised feature list must never subtract the package grant,
     * or a routine server-side change would take every valid Pro licence dark.
     */
    public function testFeatureListNeverSubtractsFromThePackageGrant(): void
    {
        foreach ([[], ['transfer.something-else'], ['']] as $features) {
            $state = $this->activeState('pro', $features);

            self::assertTrue($state->allows(EntitlementState::CAP_ARCHIVE));
            self::assertTrue($state->allows(EntitlementState::CAP_DIRECT));
        }
    }

    public function testUnknownSignedFeatureGrantsNothingOfItsOwn(): void
    {
        $state = $this->activeState('free', ['transfer.direct.v2', 'admin.everything', 'transfer.archive']);

        self::assertSame([EntitlementState::CAP_ARCHIVE], $state->capabilities);
        self::assertFalse($state->allows(EntitlementState::CAP_DIRECT));
    }

    public function testUnlicensedAndInvalidStatesAllowNothing(): void
    {
        foreach ([EntitlementState::unlicensed('no_state'), EntitlementState::invalid('bad_signature')] as $state) {
            self::assertFalse($state->isLicensed());

            foreach (EntitlementState::CAPABILITIES as $capability) {
                self::assertFalse($state->allows($capability), $state->status.' must grant nothing');
            }
        }
    }

    /** A boundary that forgets to name a capability is refused, not waved through. */
    public function testEmptyCapabilityNameNeverMatches(): void
    {
        self::assertFalse($this->activeState('pro')->allows(''));
    }

    /**
     * A "licensed" state granting nothing is a contradiction, so the factory refuses to mint one
     * rather than produce something that reports licensed while every gate refuses it.
     */
    public function testLicensedStateWithAnEmptyGrantSetCollapsesToUnlicensed(): void
    {
        $state = EntitlementState::licensed(EntitlementState::STATUS_PRO, 'pro', 'example.com', null, true, 9, [], []);

        self::assertFalse($state->isLicensed());
        self::assertSame(EntitlementState::STATUS_UNLICENSED, $state->status);
        self::assertSame('no_capability_granted', $state->reason);
    }

    /**
     * @param list<string> $features
     */
    private function activeState(string $package, array $features = []): EntitlementState
    {
        return $this->policy->evaluate(
            $this->record([
                'license_package' => $package,
                'license_features' => $features,
                'license_starts_at' => self::NOW - 86400,
                'license_expires_at' => self::NOW + 86400,
            ]),
            self::NOW,
            'example.com',
        );
    }
}
