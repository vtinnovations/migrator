<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Transfer;

use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Outbound activation/refresh transport to the single fixed V-T.ONE verify endpoint.
 *
 * The destination is a hard code constant: no configuration, request data, DNS alias, redirect,
 * scheme, port, or response may redirect it elsewhere. TLS peer/hostname verification stays on,
 * redirects are refused, timeouts and a response-size cap are enforced, and the media type is
 * checked before parsing. Each request carries a unique request_id, a UTC timestamp, and a
 * single-use random nonce; the response request_id must correlate with the one sent.
 *
 * This client only performs the exchange and returns the decoded JSON body — signature/MD5
 * verification of the signed package is {@see ExchangeResponseReader}'s responsibility.
 */
final class ExchangeClient
{
    private const ENDPOINT = 'https://www.v-t.one/api/v1/verify';
    private const MAX_BYTES = 262144; // 256 KiB response cap
    private const MAX_SKEW = 300;     // accept ±5 min server-time skew

    public function __construct(
        private readonly HttpClientInterface $client,
        private readonly string $project,
        private readonly string $projectSlug,
        private readonly string $productId,
    ) {
    }

    /**
     * Workflow A — first activation.
     *
     * @return array<string, mixed> decoded response body
     */
    public function activate(string $licenseKey, string $domain, ?int $now = null): array
    {
        return $this->exchange('activate', $licenseKey, $domain, [], $now);
    }

    /**
     * Workflow B — administrator refresh with the current stored version.
     *
     * @return array<string, mixed> decoded response body
     */
    public function refresh(string $licenseKey, string $domain, int $currentVersion, ?int $now = null): array
    {
        return $this->exchange('refresh', $licenseKey, $domain, ['current_license_version' => $currentVersion], $now);
    }

    /**
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private function exchange(string $action, string $licenseKey, string $domain, array $extra, ?int $now): array
    {
        $now ??= time();
        $requestId = $this->requestId();
        $nonce = $this->nonce();

        $payload = array_merge([
            'action' => $action,
            'project' => $this->project,
            'project_slug' => $this->projectSlug,
            'product_id' => $this->productId,
            'license_key' => $licenseKey,
            'domain' => $domain,
            'request_id' => $requestId,
            'timestamp' => $now,
            'nonce' => $nonce,
        ], $extra);

        try {
            $response = $this->client->request('POST', self::ENDPOINT, [
                'headers' => ['Content-Type' => 'application/json', 'Accept' => 'application/json'],
                'json' => $payload,
                'timeout' => 10,
                'max_duration' => 20,
                'max_redirects' => 0,
                'verify_peer' => true,
                'verify_host' => true,
            ]);

            $status = $response->getStatusCode();

            if ($status >= 500) {
                throw new ExchangeException('server_error');
            }

            if ($status < 200 || $status >= 300) {
                throw new ExchangeException('http_'.$status);
            }

            $contentType = strtolower($response->getHeaders(false)['content-type'][0] ?? '');

            if (!str_contains($contentType, 'application/json')) {
                throw new ExchangeException('bad_content_type');
            }

            $body = $response->getContent(false);

            if (\strlen($body) > self::MAX_BYTES) {
                throw new ExchangeException('response_too_large');
            }
        } catch (ExchangeException $e) {
            throw $e;
        } catch (ExceptionInterface) {
            // Transport/TLS/timeout — never erases a previously valid licence; caller preserves it.
            throw new ExchangeException('transport_error');
        }

        $data = json_decode($body, true);

        if (!\is_array($data)) {
            throw new ExchangeException('malformed_response');
        }

        if (!hash_equals($requestId, (string) ($data['request_id'] ?? ''))) {
            throw new ExchangeException('correlation_mismatch');
        }

        // The exchange succeeded but the verdict may be negative: the server answers 200 with
        // status "invalid" (well-formed request, licence not valid) or "error" and a customer-facing
        // message and NO licence package. Surface that message rather than tripping the payload
        // reader. The message is customer-facing copy (no packet material), safe to show an admin.
        $status = (string) ($data['status'] ?? '');

        if ('valid' !== $status) {
            $message = trim((string) ($data['message'] ?? ''));

            throw new ExchangeException('server_'.($status ?: 'unknown'), '' !== $message ? $message : 'Verification was rejected.');
        }

        // server_time skew is a soft signal only: the signed licence dates are authoritative, so a
        // slightly off client clock must not fail a valid activation. No exception on skew here.

        return $data;
    }

    private function requestId(): string
    {
        $b = random_bytes(16);
        $b[6] = \chr((\ord($b[6]) & 0x0F) | 0x40);
        $b[8] = \chr((\ord($b[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }

    private function nonce(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    }
}
