<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Tests\Controller;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\Migrator\Config\EntitlementEvaluator;
use Vtinnovations\Migrator\Config\EntitlementExchange;
use Vtinnovations\Migrator\Config\EntitlementModelPolicy;
use Vtinnovations\Migrator\Config\EntitlementStore;
use Vtinnovations\Migrator\Config\HostNormalizer;
use Vtinnovations\Migrator\Controller\EntitlementIntakeController;
use Vtinnovations\Migrator\Manifest\CanonicalJson;
use Vtinnovations\Migrator\Manifest\DetachedVerifier;
use Vtinnovations\Migrator\Manifest\TrustRing;
use Vtinnovations\Migrator\Tests\TestKit;
use Vtinnovations\Migrator\Transfer\ExchangeClient;
use Vtinnovations\Migrator\Transfer\ExchangeResponseReader;
use Vtinnovations\Migrator\Transfer\UpdaterJournal;
use Vtinnovations\Migrator\Transfer\UpdaterRequestVerifier;

/**
 * The public server-to-server updater endpoint. It is independent of any backend login, so its
 * ONLY trust anchor is the request signature — and every rejection is a generic 401 that leaks no
 * verification detail. A rejected delivery must never touch the stored state.
 */
final class EntitlementIntakeControllerTest extends TestCase
{
    use TestKit;

    private const PATH = '/rest/api/v1/migrator-license-updater';

    private EntitlementStore $store;

    protected function tearDown(): void
    {
        $this->cleanTmp();
    }

    private function controller(): EntitlementIntakeController
    {
        $paths = $this->paths();
        $this->store = new EntitlementStore($paths);
        $reader = new ExchangeResponseReader(new TrustRing(), new DetachedVerifier(), new CanonicalJson());
        $inventory = $this->inventoryFor(['example.com']);
        $normalizer = new HostNormalizer();
        $evaluator = new EntitlementEvaluator($this->store, $reader, $inventory, $normalizer, new EntitlementModelPolicy(), 'migrator');

        $exchange = new EntitlementExchange(
            new ExchangeClient(new MockHttpClient(), 'Migrator', 'migrator', 'vt-migrator'),
            $reader,
            $this->store,
            $evaluator,
            $inventory,
            $normalizer,
            'migrator',
        );

        return new EntitlementIntakeController(
            new UpdaterRequestVerifier(new TrustRing(), new DetachedVerifier()),
            new UpdaterJournal($paths),
            $exchange,
            'migrator',
            'vt-migrator',
        );
    }

    /**
     * @param array<string, string> $headers
     */
    private function post(string $body, array $headers = [], string $contentType = 'application/json'): Request
    {
        $server = ['CONTENT_TYPE' => $contentType];

        foreach ($headers as $name => $value) {
            $server['HTTP_'.strtoupper(str_replace('-', '_', $name))] = $value;
        }

        return Request::create(self::PATH, 'POST', [], [], [], $server, $body);
    }

    public function testUnsupportedMediaTypeIsRefusedBeforeParsing(): void
    {
        $response = ($this->controller())(
            $this->post('{"action":"license_update"}', [], 'application/x-www-form-urlencoded')
        );

        self::assertSame(415, $response->getStatusCode());
    }

    public function testOversizedBodyIsRefused(): void
    {
        $response = ($this->controller())($this->post(str_repeat('a', 262145)));

        self::assertSame(413, $response->getStatusCode());
    }

    public function testUnsignedRequestIsGenericallyDenied(): void
    {
        $response = ($this->controller())($this->post('{"action":"license_update","project_slug":"migrator"}'));

        self::assertSame(401, $response->getStatusCode());
        self::assertStringNotContainsString('signature', strtolower((string) $response->getContent()));
        self::assertStringNotContainsString('key', strtolower((string) $response->getContent()));
    }

    public function testForgedSignatureNeverWritesState(): void
    {
        $controller = $this->controller();
        $response = $controller($this->post('{"action":"license_update"}', [
            'X-VT-Request-ID' => 'req-1',
            'X-VT-Timestamp' => (string) time(),
            'X-VT-Nonce' => 'nonce-1',
            'X-VT-Key-ID' => 'vtone-2026a',
            'X-VT-Signature' => base64_encode(str_repeat("\1", 64)),
        ]));

        self::assertSame(401, $response->getStatusCode());
        self::assertFalse($this->store->exists(), 'a denied delivery must not create or replace state');
    }

    public function testUnknownKeyIdIsDenied(): void
    {
        $response = ($this->controller())($this->post('{"action":"license_update"}', [
            'X-VT-Request-ID' => 'req-2',
            'X-VT-Timestamp' => (string) time(),
            'X-VT-Nonce' => 'nonce-2',
            'X-VT-Key-ID' => 'vtone-2099x',
            'X-VT-Signature' => base64_encode(str_repeat("\1", 64)),
        ]));

        self::assertSame(401, $response->getStatusCode());
    }
}
