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

namespace Vtinnovations\Migrator\Tests\Transfer;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Vtinnovations\Migrator\Transfer\ExchangeClient;
use Vtinnovations\Migrator\Transfer\ExchangeException;

/**
 * Outbound activation/refresh transport. The destination is a fixed code constant, redirects are
 * refused, TLS verification stays on and only the documented fields are ever sent. No test here
 * contacts a live V-T.ONE endpoint.
 */
final class ExchangeClientTest extends TestCase
{
    /** @var list<array{method:string, url:string, options:array<string, mixed>}> */
    private array $calls = [];

    private function client(MockResponse|\Closure $response): ExchangeClient
    {
        $mock = new MockHttpClient(function (string $method, string $url, array $options) use ($response): MockResponse {
            $this->calls[] = ['method' => $method, 'url' => $url, 'options' => $options];

            return $response instanceof \Closure ? $response($method, $url, $options) : $response;
        });

        return new ExchangeClient($mock, 'Migrator', 'migrator', 'vt-migrator');
    }

    private static function ok(array $body): MockResponse
    {
        return new MockResponse(
            (string) json_encode($body),
            ['http_code' => 200, 'response_headers' => ['content-type' => 'application/json']],
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function lastPayload(): array
    {
        $options = $this->calls[array_key_last($this->calls)]['options'];
        $body = $options['body'] ?? null;
        $decoded = \is_string($body) ? json_decode($body, true) : ($options['json'] ?? null);

        self::assertIsArray($decoded);

        return $decoded;
    }

    public function testActivateSendsOnlyTheDocumentedFieldsToTheFixedEndpoint(): void
    {
        $client = $this->client(function (string $method, string $url, array $options): MockResponse {
            $payload = json_decode((string) ($options['body'] ?? '{}'), true);

            return self::ok(['status' => 'valid', 'request_id' => $payload['request_id'], 'license_payload_b64' => 'x']);
        });

        $client->activate('AAAAA-BBBBB', 'example.com', 1784880547);

        $call = $this->calls[0];
        self::assertSame('POST', $call['method']);
        self::assertSame('https://www.v-t.one/api/v1/verify', $call['url']);
        self::assertSame(0, $call['options']['max_redirects']);
        self::assertTrue($call['options']['verify_peer']);
        self::assertTrue($call['options']['verify_host']);

        $payload = $this->lastPayload();
        self::assertSame([
            'action', 'domain', 'license_key', 'nonce', 'product_id', 'project', 'project_slug', 'request_id', 'timestamp',
        ], $this->sortedKeys($payload));
        self::assertSame('activate', $payload['action']);
        self::assertSame('Migrator', $payload['project']);
        self::assertSame('migrator', $payload['project_slug']);
        self::assertSame('vt-migrator', $payload['product_id']);
        self::assertSame('example.com', $payload['domain']);
        self::assertSame(1784880547, $payload['timestamp']);
        self::assertNotSame('', $payload['nonce']);
    }

    public function testRefreshAddsTheCurrentVersionAndNothingElse(): void
    {
        $client = $this->client(function (string $method, string $url, array $options): MockResponse {
            $payload = json_decode((string) ($options['body'] ?? '{}'), true);

            return self::ok(['status' => 'valid', 'request_id' => $payload['request_id'], 'license_payload_b64' => 'x']);
        });

        $client->refresh('AAAAA-BBBBB', 'example.com', 7, 1784880547);

        $payload = $this->lastPayload();
        self::assertSame('refresh', $payload['action']);
        self::assertSame(7, $payload['current_license_version']);
        self::assertSame([
            'action', 'current_license_version', 'domain', 'license_key', 'nonce', 'product_id', 'project',
            'project_slug', 'request_id', 'timestamp',
        ], $this->sortedKeys($payload));
    }

    public function testEachRequestUsesAFreshRequestIdAndNonce(): void
    {
        $client = $this->client(function (string $method, string $url, array $options): MockResponse {
            $payload = json_decode((string) ($options['body'] ?? '{}'), true);

            return self::ok(['status' => 'valid', 'request_id' => $payload['request_id'], 'license_payload_b64' => 'x']);
        });

        $client->activate('AAAAA-BBBBB', 'example.com', 1784880547);
        $first = $this->lastPayload();
        $client->activate('AAAAA-BBBBB', 'example.com', 1784880547);
        $second = $this->lastPayload();

        self::assertNotSame($first['request_id'], $second['request_id']);
        self::assertNotSame($first['nonce'], $second['nonce']);
    }

    public function testUncorrelatedResponseIsRejected(): void
    {
        $client = $this->client(self::ok(['status' => 'valid', 'request_id' => 'someone-elses-id']));

        $this->expectExchangeCategory($client, 'correlation_mismatch');
    }

    public function testServerErrorNeverLooksLikeAVerdict(): void
    {
        $client = $this->client(new MockResponse('', ['http_code' => 503]));

        $this->expectExchangeCategory($client, 'server_error');
    }

    public function testNonJsonResponseIsRefusedBeforeParsing(): void
    {
        $client = $this->client(new MockResponse('<html>nope</html>', [
            'http_code' => 200,
            'response_headers' => ['content-type' => 'text/html'],
        ]));

        $this->expectExchangeCategory($client, 'bad_content_type');
    }

    public function testAuthenticatedDenialSurfacesTheServerMessageOnly(): void
    {
        $client = $this->client(function (string $method, string $url, array $options): MockResponse {
            $payload = json_decode((string) ($options['body'] ?? '{}'), true);

            return self::ok([
                'status' => 'invalid',
                'request_id' => $payload['request_id'],
                'message' => 'This licence key is not valid for that domain.',
            ]);
        });

        try {
            $client->activate('AAAAA-BBBBB', 'example.com', 1784880547);
            self::fail('a negative verdict must not be treated as success');
        } catch (ExchangeException $e) {
            self::assertSame('server_invalid', $e->category());
            self::assertSame('This licence key is not valid for that domain.', $e->getMessage());
        }
    }

    private function expectExchangeCategory(ExchangeClient $client, string $category): void
    {
        try {
            $client->activate('AAAAA-BBBBB', 'example.com', 1784880547);
            self::fail(sprintf('expected ExchangeException "%s"', $category));
        } catch (ExchangeException $e) {
            self::assertSame($category, $e->category());
        }
    }

    /**
     * @param array<string, mixed> $payload
     *
     * @return list<string>
     */
    private function sortedKeys(array $payload): array
    {
        $keys = array_keys($payload);
        sort($keys);

        return $keys;
    }
}
