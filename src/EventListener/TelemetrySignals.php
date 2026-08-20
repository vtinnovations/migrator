<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\EventListener;

use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Vtinnovations\Migrator\Config\HostNormalizer;

/**
 * The two documented server-to-server invocation signals, both to the single fixed endpoint
 * https://www.v-t.one/rest/api/v1/log-envoke. They are DISTINCT event shapes and never merged:
 *
 *   - per-invocation: {"project","domain"} — at most once per request, no licence key;
 *   - session module-entry: {"domain","key"} — exactly once per authenticated backend session,
 *     fired only when a cryptographically authenticated licence exists, keyed and deduplicated in
 *     the backend session.
 *
 * Signals are queued when the protected module is entered and flushed on kernel.terminate, so they
 * never delay rendering. Delivery is best-effort with a short timeout, TLS verification on, no
 * redirects and no response processing; a failure is silent and never retried within the session,
 * and never affects licensing or rendering. The session key never reaches the browser or any log.
 */
final class TelemetrySignals
{
    private const ENDPOINT = 'https://www.v-t.one/rest/api/v1/log-envoke';

    /** @var list<array<string, string>> queued payloads to POST on terminate */
    private array $pending = [];

    private bool $invocationSent = false;

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly HostNormalizer $normalizer,
        private readonly string $project,
    ) {
    }

    /**
     * Called once when the protected backend module is entered.
     *
     * @param array{key:string, domain:string}|null $authenticated result of
     *        EntitlementEvaluator::authenticatedKeyAndDomain() — non-null only when licensed
     * @param callable():bool $claimSession atomically claims the once-per-session marker; returns
     *        true only for the first caller in this authenticated session (keyed by project/scope)
     */
    public function onModuleEntry(string $currentHost, ?array $authenticated, callable $claimSession): void
    {
        $host = $this->normalizer->normalize($currentHost);

        // Per-invocation: project + domain, at most once per request. Never carries the key.
        if (!$this->invocationSent && '' !== $host) {
            $this->pending[] = ['project' => $this->project, 'domain' => $host];
            $this->invocationSent = true;
        }

        // Session module-entry: domain + key, once per authenticated session. Claim BEFORE queuing
        // so a delivery failure cannot cause a second attempt in the same session.
        if (null !== $authenticated && '' !== $authenticated['key'] && '' !== $authenticated['domain']) {
            if ($claimSession()) {
                $this->pending[] = ['domain' => $authenticated['domain'], 'key' => $authenticated['key']];
            }
        }
    }

    public function onTerminate(TerminateEvent $event): void
    {
        if ([] === $this->pending) {
            return;
        }

        $queued = $this->pending;
        $this->pending = [];

        foreach ($queued as $payload) {
            try {
                $response = $this->client->request('POST', self::ENDPOINT, [
                    'headers' => ['Content-Type' => 'application/json'],
                    'json' => $payload,
                    'timeout' => 3,
                    'max_duration' => 5,
                    'max_redirects' => 0,
                    'verify_peer' => true,
                    'verify_host' => true,
                ]);

                // Touch the status so the request is actually dispatched, but never process or log
                // the response body.
                $response->getStatusCode();
            } catch (\Throwable) {
                // Silent: telemetry must never affect licensing, rendering, or user-facing execution.
            }
        }
    }
}
