<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Tests\EventListener;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Vtinnovations\Migrator\Config\EntitlementEvaluator;
use Vtinnovations\Migrator\Config\EntitlementExchange;
use Vtinnovations\Migrator\Config\EntitlementModelPolicy;
use Vtinnovations\Migrator\Config\EntitlementStore;
use Vtinnovations\Migrator\Config\HostNormalizer;
use Vtinnovations\Migrator\EventListener\DataContainer\EntitlementFields;
use Vtinnovations\Migrator\Manifest\CanonicalJson;
use Vtinnovations\Migrator\Manifest\DetachedVerifier;
use Vtinnovations\Migrator\Manifest\TrustRing;
use Vtinnovations\Migrator\Tests\TestKit;
use Vtinnovations\Migrator\Transfer\ExchangeClient;
use Vtinnovations\Migrator\Transfer\ExchangeException;
use Vtinnovations\Migrator\Transfer\ExchangeResponseReader;

/**
 * The three pure action methods (activate/refresh/remove), driven through the REAL exchange with
 * only the HTTP transport mocked. Nothing here contacts V-T.ONE.
 *
 * `onSubmit()` — the actual DCA dispatcher these three back — is deliberately NOT unit-tested here:
 * it reads the request and reports through Contao's `Message` class, both of which need a booted
 * Contao kernel. That dispatch is covered by runtime acceptance instead (see
 * `tools/verify-licence-surface.php` and `ActionWiringTest`'s static trace). What belongs in a pure
 * PHPUnit suite is exactly what is pure: does activate/refresh/remove do the right thing against a
 * mocked transport, throw on refusal, and never touch state on failure.
 */
final class EntitlementFieldsTest extends TestCase
{
    use TestKit;

    private EntitlementStore $store;

    /** @var list<string> raw request bodies the transport was asked to send */
    private array $sent = [];

    protected function tearDown(): void
    {
        $this->cleanTmp();
    }

    /**
     * @param array<string, mixed>|null $serverResponse decoded body the mocked endpoint returns
     * @param list<string>              $rootDomains
     */
    private function fields(?array $serverResponse = null, array $rootDomains = ['example.com'], string $requestHost = 'example.com'): EntitlementFields
    {
        $this->sent = [];

        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($serverResponse): MockResponse {
            $body = (string) ($options['body'] ?? '');
            $this->sent[] = $body;

            $decoded = json_decode($body, true);
            $payload = $serverResponse ?? ['status' => 'invalid', 'message' => 'Licence key not recognised.'];
            // Correlate, or the client rejects the answer before the verdict is even read.
            $payload['request_id'] ??= (string) ($decoded['request_id'] ?? '');

            return new MockResponse(
                (string) json_encode($payload),
                ['response_headers' => ['content-type' => 'application/json']],
            );
        });

        $paths = $this->paths();
        $this->store = new EntitlementStore($paths);
        $reader = new ExchangeResponseReader(new TrustRing(), new DetachedVerifier(), new CanonicalJson());
        $inventory = $this->inventoryFor($rootDomains);
        $normalizer = new HostNormalizer();

        $exchange = new EntitlementExchange(
            new ExchangeClient($client, 'Migrator', 'migrator', 'vt-migrator'),
            $reader,
            $this->store,
            new EntitlementEvaluator($this->store, $reader, $inventory, $normalizer, new EntitlementModelPolicy(), 'migrator'),
            $inventory,
            $normalizer,
            'migrator',
        );

        $stack = new RequestStack();
        $stack->push(Request::create('https://'.$requestHost.'/contao?do=settings'));

        return new EntitlementFields($exchange, $stack);
    }

    /**
     * An empty key is what a normal Settings save with no key typed carries; it must never start a
     * licence exchange, but it IS a real refusal (activate() is only reached from onSubmit() when
     * the activate button was actually clicked, so a clicked "Verify & activate" with a blank field
     * must tell the operator to enter a key, not silently do nothing).
     */
    public function testEmptyKeyIsRefusedLocallyAndContactsNobody(): void
    {
        $fields = $this->fields();

        foreach (['', '   '] as $key) {
            try {
                $fields->activate($key);
                self::fail('an empty key must be refused');
            } catch (\Exception) {
                // expected
            }
        }

        self::assertSame([], $this->sent, 'an empty key must not reach the transport');
    }

    public function testAServerRefusalIsReportedAndNothingIsStored(): void
    {
        $fields = $this->fields();

        try {
            $fields->activate('AAAAA-BBBBB-CCCCC-DDDDD');
            self::fail('a refused key must not activate');
        } catch (\Exception $e) {
            self::assertNotSame('', $e->getMessage());
        }

        self::assertCount(1, $this->sent, 'activation must reach the transport exactly once');
        self::assertFalse($this->store->exists(), 'a refused activation must store nothing');
    }

    public function testActivationSendsTheDocumentedActivatePacket(): void
    {
        try {
            $this->fields()->activate('AAAAA-BBBBB-CCCCC-DDDDD');
        } catch (\Exception) {
            // The refusal is asserted elsewhere; here only the outbound packet matters.
        }

        $packet = json_decode($this->sent[0] ?? '{}', true);

        self::assertSame('activate', $packet['action'] ?? null);
        self::assertSame('migrator', $packet['project_slug'] ?? null);
        self::assertSame('vt-migrator', $packet['product_id'] ?? null);
        self::assertSame('AAAAA-BBBBB-CCCCC-DDDDD', $packet['license_key'] ?? null);
        self::assertSame('example.com', $packet['domain'] ?? null, 'the domain must come from the trusted inventory');
        self::assertNotSame('', (string) ($packet['nonce'] ?? ''));
        self::assertArrayNotHasKey('current_license_version', $packet, 'activation carries no version');
    }

    /**
     * A spoofed Host header must not choose the identity this installation activates under: the
     * request host is accepted only when it is already in the trusted configured inventory.
     */
    public function testASpoofedHostCannotChooseTheActivationDomain(): void
    {
        $fields = $this->fields(null, ['primary.example.com', 'second.example.com'], 'attacker.example.net');

        try {
            $fields->activate('AAAAA-BBBBB-CCCCC-DDDDD');
        } catch (\Exception) {
            // Only the outbound domain matters here.
        }

        $packet = json_decode($this->sent[0] ?? '{}', true);

        self::assertSame('primary.example.com', $packet['domain'] ?? null);
    }

    /**
     * Refresh uses the STORED key. With nothing stored it must fail locally, before any transport
     * is created — there is no key it could legitimately send.
     */
    public function testRefreshWithoutStoredStateFailsBeforeAnyRequest(): void
    {
        $fields = $this->fields();

        $this->expectException(\Exception::class);

        try {
            $fields->refresh();
        } finally {
            self::assertSame([], $this->sent, 'refresh must not contact the server with no stored key');
        }
    }

    public function testRemoveIsLocalOnlyAndRestoresTheUnlicensedDefault(): void
    {
        $fields = $this->fields();

        $fields->remove(); // must not throw
        self::assertSame([], $this->sent, 'removal is a local operation');
        self::assertFalse($this->store->exists());
    }

    /**
     * A transport failure must never erase or fabricate state. `activate()` itself is the PURE
     * layer — it is expected to propagate the typed `ExchangeException` with its raw category
     * intact (e.g. "transport_error"); that category is not admin-facing on its own. Translating it
     * to a safe, generic message is `reason()`'s job, exercised separately below, and is only ever
     * invoked from `onSubmit()`'s catch block — the one place anything reaches the admin's screen.
     */
    public function testTransportFailurePreservesState(): void
    {
        $client = new MockHttpClient(static fn (): MockResponse => new MockResponse('', ['error' => 'connection refused']));

        $paths = $this->paths();
        $this->store = new EntitlementStore($paths);
        $reader = new ExchangeResponseReader(new TrustRing(), new DetachedVerifier(), new CanonicalJson());
        $inventory = $this->inventoryFor(['example.com']);
        $normalizer = new HostNormalizer();

        $exchange = new EntitlementExchange(
            new ExchangeClient($client, 'Migrator', 'migrator', 'vt-migrator'),
            $reader,
            $this->store,
            new EntitlementEvaluator($this->store, $reader, $inventory, $normalizer, new EntitlementModelPolicy(), 'migrator'),
            $inventory,
            $normalizer,
            'migrator',
        );

        $stack = new RequestStack();
        $stack->push(Request::create('https://example.com/contao'));

        try {
            (new EntitlementFields($exchange, $stack))->activate('AAAAA-BBBBB-CCCCC-DDDDD');
            self::fail('a transport failure must not report success');
        } catch (ExchangeException $e) {
            self::assertSame('transport_error', $e->category());
        }

        self::assertFalse($this->store->exists());
    }

    /**
     * @return iterable<string, array{string, string}> category => forbidden substring in the
     *                                                  translated result (case-insensitive)
     */
    public static function leakyCategoryProvider(): iterable
    {
        yield 'transport failure' => ['transport_error', 'transport_error'];
        yield 'signing key store empty' => ['signing_key_store_empty', 'signing_key_store_empty'];
        yield 'md5 mismatch' => ['md5_mismatch', 'md5_mismatch'];
        yield 'unknown signing key' => ['unknown_signing_key', 'unknown_signing_key'];
        yield 'bad envelope signature' => ['bad_envelope_signature', 'bad_envelope_signature'];
        yield 'schema version' => ['schema_version', 'schema_version'];
        yield 'domain mismatch' => ['domain_mismatch', 'domain_mismatch'];
        yield 'rollback rejected' => ['rollback_rejected', 'rollback_rejected'];
    }

    /**
     * `reason()` is the one place `onSubmit()` turns an internal verification category into
     * something an administrator may see. Every non-`server_*` category must map to one of the
     * fixed, safe catalogue keys — never the raw category itself.
     */
    #[DataProvider('leakyCategoryProvider')]
    public function testReasonNeverLeaksAnInternalCategory(string $category, string $forbidden): void
    {
        $translated = $this->reason(new ExchangeException($category));

        self::assertStringNotContainsStringIgnoringCase($forbidden, $translated);
    }

    /**
     * The one deliberate exception: a `server_*` category carries the V-T.ONE server's own
     * customer-facing message (never packet material — see ExchangeClient), so it passes through
     * verbatim rather than being collapsed to a generic line.
     */
    public function testReasonPassesThroughTheServersOwnCustomerFacingMessage(): void
    {
        $translated = $this->reason(new ExchangeException('server_invalid', 'That licence key is not recognised.'));

        self::assertSame('That licence key is not recognised.', $translated);
    }

    private function reason(ExchangeException $e): string
    {
        $method = new \ReflectionMethod(EntitlementFields::class, 'reason');

        return (string) $method->invoke($this->fields(), $e);
    }
}
