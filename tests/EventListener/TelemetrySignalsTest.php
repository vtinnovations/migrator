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

namespace Vtinnovations\Migrator\Tests\EventListener;

use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Vtinnovations\Migrator\Config\HostNormalizer;
use Vtinnovations\Migrator\EventListener\TelemetrySignals;

/**
 * The two documented server-to-server signals. They are distinct event shapes and must never be
 * merged: the per-invocation event never carries a key, and the session module-entry event fires
 * exactly once per authenticated session — claimed BEFORE delivery so a failure cannot retry.
 */
final class TelemetrySignalsTest extends TestCase
{
    /** @var list<array{url:string, payload:array<string, mixed>}> */
    private array $sent = [];

    private function signals(bool $failDelivery = false): TelemetrySignals
    {
        $client = new MockHttpClient(function (string $method, string $url, array $options) use ($failDelivery): MockResponse {
            $payload = json_decode((string) ($options['body'] ?? '{}'), true);
            $this->sent[] = ['url' => $url, 'payload' => \is_array($payload) ? $payload : []];

            self::assertSame('POST', $method);
            self::assertSame(0, $options['max_redirects']);
            self::assertTrue($options['verify_peer']);

            return $failDelivery
                ? new MockResponse('', ['error' => 'connection refused'])
                : new MockResponse('', ['http_code' => 204]);
        });

        return new TelemetrySignals($client, new HostNormalizer(), 'Migrator');
    }

    private function flush(TelemetrySignals $signals): void
    {
        $kernel = new class() implements HttpKernelInterface {
            public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = true): Response
            {
                return new Response();
            }
        };

        $signals->onTerminate(new TerminateEvent($kernel, new Request(), new Response()));
    }

    /** @return callable():bool a claim that succeeds exactly once, like the session marker */
    private function singleClaim(): callable
    {
        $claimed = false;

        return static function () use (&$claimed): bool {
            if ($claimed) {
                return false;
            }

            return $claimed = true;
        };
    }

    public function testUnlicensedInstanceSendsOnlyTheInvocationSignalAndNeverAKey(): void
    {
        $signals = $this->signals();
        $signals->onModuleEntry('Example.com:8443', null, $this->singleClaim());
        $this->flush($signals);

        self::assertCount(1, $this->sent);
        self::assertSame('https://www.v-t.one/rest/api/v1/log-envoke', $this->sent[0]['url']);
        self::assertSame(['project' => 'Migrator', 'domain' => 'example.com'], $this->sent[0]['payload']);
        self::assertArrayNotHasKey('key', $this->sent[0]['payload']);
    }

    public function testLicensedModuleEntrySendsBothShapesExactlyOnce(): void
    {
        $signals = $this->signals();
        $signals->onModuleEntry(
            'example.com',
            ['key' => 'AAAAA-BBBBB-CCCCC', 'domain' => 'example.com'],
            $this->singleClaim(),
        );
        $this->flush($signals);

        self::assertCount(2, $this->sent);
        self::assertSame(['project' => 'Migrator', 'domain' => 'example.com'], $this->sent[0]['payload']);
        self::assertSame(['domain' => 'example.com', 'key' => 'AAAAA-BBBBB-CCCCC'], $this->sent[1]['payload']);
    }

    public function testSessionEventUsesTheAuthenticatedMatchedDomainNotTheRequestHost(): void
    {
        $signals = $this->signals();
        $signals->onModuleEntry(
            'backend.example.net',
            ['key' => 'AAAAA-BBBBB-CCCCC', 'domain' => 'example.com'],
            $this->singleClaim(),
        );
        $this->flush($signals);

        self::assertSame('example.com', $this->sent[1]['payload']['domain']);
    }

    public function testAlreadyClaimedSessionSendsNoSecondKeyEvent(): void
    {
        $signals = $this->signals();
        $signals->onModuleEntry('example.com', ['key' => 'K', 'domain' => 'example.com'], static fn (): bool => false);
        $this->flush($signals);

        self::assertCount(1, $this->sent, 'reloads/parallel tabs must not re-emit the session event');
        self::assertArrayNotHasKey('key', $this->sent[0]['payload']);
    }

    public function testRepeatedModuleEntryInOneRequestDoesNotDuplicateTheInvocationSignal(): void
    {
        $signals = $this->signals();
        $claim = $this->singleClaim();
        $signals->onModuleEntry('example.com', ['key' => 'K', 'domain' => 'example.com'], $claim);
        $signals->onModuleEntry('example.com', ['key' => 'K', 'domain' => 'example.com'], $claim);
        $this->flush($signals);

        self::assertCount(2, $this->sent);
    }

    public function testNoAuthenticKeyMeansNoSessionEventAndNoClaim(): void
    {
        $signals = $this->signals();
        $claimed = false;
        $signals->onModuleEntry('example.com', ['key' => '', 'domain' => 'example.com'], static function () use (&$claimed): bool {
            $claimed = true;

            return true;
        });
        $this->flush($signals);

        self::assertFalse($claimed, 'the session must stay unclaimed when there is no authentic key');
        self::assertCount(1, $this->sent);
    }

    public function testEmptyHostSendsNoInvocationSignal(): void
    {
        $signals = $this->signals();
        $signals->onModuleEntry('', null, $this->singleClaim());
        $this->flush($signals);

        self::assertSame([], $this->sent);
    }

    public function testDeliveryFailureIsSilentAndNotRetried(): void
    {
        $signals = $this->signals(true);
        $signals->onModuleEntry('example.com', ['key' => 'K', 'domain' => 'example.com'], $this->singleClaim());
        $this->flush($signals);

        self::assertCount(2, $this->sent, 'both events were attempted');

        $this->sent = [];
        $this->flush($signals);

        self::assertSame([], $this->sent, 'a failed delivery must not be retried');
    }
}
