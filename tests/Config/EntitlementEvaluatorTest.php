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
use Vtinnovations\Migrator\Config\EntitlementEvaluator;
use Vtinnovations\Migrator\Config\EntitlementModelPolicy;
use Vtinnovations\Migrator\Config\EntitlementState;
use Vtinnovations\Migrator\Config\EntitlementStore;
use Vtinnovations\Migrator\Config\HostNormalizer;
use Vtinnovations\Migrator\Manifest\CanonicalJson;
use Vtinnovations\Migrator\Manifest\DetachedVerifier;
use Vtinnovations\Migrator\Manifest\TrustRing;
use Vtinnovations\Migrator\Tests\TestKit;
use Vtinnovations\Migrator\Transfer\ExchangeResponseReader;

/**
 * The read-side gate. Every evaluation re-runs the crypto pipeline over the stored bytes, so a
 * hand-written or copied state file can never grant an entitlement — and there is no
 * `isLicensed()` shortcut on the evaluator that one edit could turn into an unconditional yes.
 */
final class EntitlementEvaluatorTest extends TestCase
{
    use TestKit;

    private EntitlementStore $store;

    protected function tearDown(): void
    {
        $this->cleanTmp();
    }

    /**
     * @param list<string> $rootDomains
     */
    private function evaluator(array $rootDomains = ['example.com']): EntitlementEvaluator
    {
        $this->store = new EntitlementStore($this->paths());

        return new EntitlementEvaluator(
            $this->store,
            new ExchangeResponseReader(new TrustRing(), new DetachedVerifier(), new CanonicalJson()),
            $this->inventoryFor($rootDomains),
            new HostNormalizer(),
            new EntitlementModelPolicy(),
            'migrator',
        );
    }

    /**
     * @param array<string, mixed> $envelopeOverrides
     */
    private function storeDocument(array $documentOverrides = [], array $envelopeOverrides = []): void
    {
        $bytes = (string) json_encode($this->document($documentOverrides), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $envelope = array_merge([
            'project' => 'Migrator',
            'project_slug' => 'migrator',
            'license_version' => 7,
            'license_md5' => md5($bytes),
            'generated_at' => 1784880547,
            'key_id' => 'vtone-2026a',
            'signature_algorithm' => 'ed25519',
            'signature' => base64_encode(str_repeat("\0", 64)),
        ], $envelopeOverrides);

        $this->store->withLock(fn () => $this->store->save($bytes, $envelope, 'example.com'));
    }

    public function testNoStoredStateIsUnlicensed(): void
    {
        $state = $this->evaluator()->evaluate();

        self::assertFalse($state->isLicensed());
        self::assertSame(EntitlementState::STATUS_UNLICENSED, $state->status);
        self::assertSame('no_state', $state->reason);
    }

    public function testHandCraftedStateCannotGrantAnEntitlement(): void
    {
        $evaluator = $this->evaluator();
        $this->storeDocument();

        $state = $evaluator->evaluate();

        self::assertFalse($state->isLicensed(), 'an unsigned document must never authorise anything');
        self::assertSame(EntitlementState::STATUS_INVALID, $state->status);
        self::assertSame('bad_envelope_signature', $state->reason);
    }

    public function testUnknownSigningKeyFailsClosed(): void
    {
        $evaluator = $this->evaluator();
        $this->storeDocument([], ['key_id' => 'vtone-2099x']);

        self::assertSame('unknown_signing_key', $evaluator->evaluate()->reason);
    }

    public function testUnknownAlgorithmFailsClosed(): void
    {
        $evaluator = $this->evaluator();
        $this->storeDocument([], ['signature_algorithm' => 'hmac-sha256']);

        self::assertSame('unknown_algorithm', $evaluator->evaluate()->reason);
    }

    public function testCorruptedStateFileIsUnlicensedRatherThanFatal(): void
    {
        $evaluator = $this->evaluator();
        $this->storeDocument();
        file_put_contents($this->paths()->entitlementFile(), '{"payload_b64":"!!!not-base64!!!"}');

        self::assertFalse($evaluator->evaluate()->isLicensed());
    }

    public function testKeyIsNeverExposedForAnUnlicensedState(): void
    {
        $evaluator = $this->evaluator();
        $this->storeDocument();

        self::assertNull($evaluator->authenticatedKeyAndDomain(), 'the telemetry key comes only from an authentic record');
    }

    public function testEvaluationIsRecomputedAndNotCachedAcrossRemoval(): void
    {
        $evaluator = $this->evaluator();
        $this->storeDocument();
        self::assertSame(EntitlementState::STATUS_INVALID, $evaluator->evaluate()->status);

        $this->store->withLock(fn () => $this->store->remove());

        self::assertSame(EntitlementState::STATUS_UNLICENSED, $evaluator->evaluate()->status);
    }
}
