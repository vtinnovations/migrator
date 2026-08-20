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
use Vtinnovations\Migrator\Config\HostNormalizer;
use Vtinnovations\Migrator\Tests\TestKit;

/**
 * Exact-host binding. Normalization may only change REPRESENTATION; apex, `www`, siblings and
 * nested subdomains stay four different identities, and the activation predicate is an exact set
 * intersection with the trusted root-page inventory — never suffix or wildcard matching.
 */
final class HostBindingTest extends TestCase
{
    use TestKit;

    private HostNormalizer $normalizer;

    protected function setUp(): void
    {
        $this->normalizer = new HostNormalizer();
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function representationProvider(): iterable
    {
        yield 'lowercased' => ['Example.COM', 'example.com'];
        yield 'one trailing dot' => ['example.com.', 'example.com'];
        yield 'approved port' => ['example.com:8443', 'example.com'];
        yield 'scheme and path' => ['https://example.com/contao?do=settings', 'example.com'];
        yield 'userinfo' => ['user:pass@example.com', 'example.com'];
        yield 'whitespace' => ['  example.com  ', 'example.com'];
        yield 'empty' => ['', ''];
    }

    #[DataProvider('representationProvider')]
    public function testNormalizationOnlyChangesRepresentation(string $input, string $expected): void
    {
        self::assertSame($expected, $this->normalizer->normalize($input));
    }

    public function testNormalizationNeverBroadensScope(): void
    {
        self::assertSame('www.example.com', $this->normalizer->normalize('WWW.Example.com'));
        self::assertNotSame(
            $this->normalizer->normalize('example.com'),
            $this->normalizer->normalize('www.example.com'),
        );
        self::assertNotSame(
            $this->normalizer->normalize('shop.example.com'),
            $this->normalizer->normalize('admin.shop.example.com'),
        );
    }

    public function testIdnIsConvertedConsistentlyWithoutChangingLabels(): void
    {
        if (!\function_exists('idn_to_ascii')) {
            self::markTestSkipped('ext-intl is required for IDN normalization.');
        }

        self::assertSame('xn--exmple-cua.de', $this->normalizer->normalize('exämple.de'));
        self::assertSame('xn--exmple-cua.de', $this->normalizer->normalize('EXÄMPLE.DE.'));
    }

    public function testInventoryIsBuiltFromRootPagesAndDeduplicated(): void
    {
        $inventory = $this->inventoryFor(['Example.com', 'staging.example.com', 'example.com.']);

        self::assertSame(['example.com', 'staging.example.com'], $inventory->inventory());
    }

    public function testIntersectionIsExactAndNeverSuffixBased(): void
    {
        $inventory = $this->inventoryFor(['www.example.com']);

        self::assertSame('www.example.com', $inventory->firstIntersection(['staging.example.com', 'www.example.com']));
        self::assertNull($inventory->firstIntersection(['example.com']), 'apex must not authorise www');
        self::assertNull($inventory->firstIntersection(['shop.www.example.com']), 'child must not authorise parent');
        self::assertNull($inventory->firstIntersection(['*.example.com']), 'wildcards are not a scope');
        self::assertNull($inventory->firstIntersection([]));
    }

    public function testAnySingleMatchAmongSeveralConfiguredDomainsIsSufficient(): void
    {
        $inventory = $this->inventoryFor(['a.example.com', 'b.example.com', 'c.example.com']);

        self::assertSame('b.example.com', $inventory->firstIntersection(['b.example.com']));
    }

    public function testVerificationDomainPrefersTheTrustedCurrentHostThenThePrimary(): void
    {
        $inventory = $this->inventoryFor(['primary.example.com', 'second.example.com']);

        self::assertSame('second.example.com', $inventory->selectVerificationDomain('Second.example.com:443'));
        self::assertSame('primary.example.com', $inventory->selectVerificationDomain('attacker.example.net'));
        self::assertSame('primary.example.com', $inventory->selectVerificationDomain(null));
    }

    public function testActivationIsRefusedWhenNoTrustedDomainIsConfigured(): void
    {
        self::assertNull($this->inventoryFor([])->selectVerificationDomain('example.com'));
    }

    /**
     * @return iterable<string, array{list<string>, bool}>
     */
    public static function signedDomainSetProvider(): iterable
    {
        yield 'canonical pair' => [['example.com', 'staging.example.com'], true];
        yield 'single entry' => [['example.com'], true];
        yield 'empty set' => [[], false];
        yield 'unsorted' => [['staging.example.com', 'example.com'], false];
        yield 'duplicate' => [['example.com', 'example.com'], false];
        yield 'wildcard' => [['*.example.com'], false];
        yield 'wildcard among valid' => [['example.com', '*.example.com'], false];
        yield 'uppercase' => [['Example.com'], false];
        yield 'empty entry' => [['', 'example.com'], false];
    }

    /**
     * The signed set is validated exactly as received. Sorting it locally before the check would
     * let a non-canonical payload pass, because the signature covers the list in its given order.
     *
     * @param list<string> $domains
     */
    #[DataProvider('signedDomainSetProvider')]
    public function testSignedDomainSetMustBeCanonical(array $domains, bool $expected): void
    {
        self::assertSame($expected, $this->normalizer->isCanonicalSet($domains));
    }

    /**
     * Both the read side and the WRITE side must apply the canonical-set rule. If activation
     * accepted a payload the evaluator later rejects, the operator would be told the licence
     * activated while the instance stayed dark — with nothing pointing at the cause.
     */
    public function testBothSidesEnforceTheCanonicalSetRule(): void
    {
        $root = \dirname(__DIR__, 2).'/src/Config/';

        foreach (['EntitlementEvaluator.php', 'EntitlementExchange.php'] as $file) {
            self::assertStringContainsString(
                'isCanonicalSet(',
                (string) file_get_contents($root.$file),
                sprintf('%s must reject a non-canonical signed domain set.', $file),
            );
        }

        // The allowance must be a positive integer on both sides, but neither may enforce
        // count(domains) <= maxDomains: the server deliberately lets existing bindings survive a
        // lowered allowance, and a client-side guard would take a licensed installation dark.
        foreach (['EntitlementEvaluator.php', 'EntitlementExchange.php'] as $file) {
            $code = (string) file_get_contents($root.$file);

            self::assertStringContainsString('maxDomains() < 1', $code, $file);
            self::assertStringNotContainsString('count($domains) >', $code, $file);
            self::assertStringNotContainsString('\count($domains) >', $code, $file);
        }
    }
}
