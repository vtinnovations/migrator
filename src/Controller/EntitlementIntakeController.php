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

namespace Vtinnovations\Migrator\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\Migrator\Config\EntitlementExchange;
use Vtinnovations\Migrator\Transfer\ExchangeException;
use Vtinnovations\Migrator\Transfer\UpdaterJournal;
use Vtinnovations\Migrator\Transfer\UpdaterRequestVerifier;

/**
 * Public, server-to-server endpoint where V-T.ONE pushes a licence change:
 * POST /rest/api/v1/migrator-license-updater.
 *
 * Independent of any backend login (it is not browser-driven), authenticated ENTIRELY by the
 * request signature — never by Origin/Referer/IP. Kept deliberately thin: it enforces request
 * shape, authenticates the delivery, dedupes by request id, and delegates the payload verification
 * and atomic apply to the same building blocks the activation path uses. Every failure is a
 * generic 401/403 with no verification detail.
 */
final class EntitlementIntakeController
{
    /** The exact served path bound into the request signature (see vt-one/request-sig-v1). */
    private const PATH = '/rest/api/v1/migrator-license-updater';

    private const MAX_BODY = 262144; // 256 KiB

    public function __construct(
        private readonly UpdaterRequestVerifier $requestVerifier,
        private readonly UpdaterJournal $journal,
        private readonly EntitlementExchange $exchange,
        private readonly string $projectSlug,
        private readonly string $productId,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $now = time();

        // Content type + body-size limits BEFORE parsing.
        if (!str_contains(strtolower((string) $request->headers->get('Content-Type', '')), 'application/json')) {
            return new JsonResponse(['status' => 'error', 'message' => 'Unsupported Media Type.'], 415);
        }

        $body = $request->getContent();

        if (\strlen($body) > self::MAX_BODY) {
            return new JsonResponse(['status' => 'error', 'message' => 'Payload too large.'], 413);
        }

        $headers = [
            'requestId' => (string) $request->headers->get('X-VT-Request-ID', ''),
            'timestamp' => (int) $request->headers->get('X-VT-Timestamp', '0'),
            'nonce' => (string) $request->headers->get('X-VT-Nonce', ''),
            'keyId' => (string) $request->headers->get('X-VT-Key-ID', ''),
            'signature' => (string) $request->headers->get('X-VT-Signature', ''),
        ];

        // Authenticate the delivery. Path is the fixed served path, not a client-supplied value.
        if (!$this->requestVerifier->verify('POST', self::PATH, $headers, $body, $now)) {
            return $this->deny();
        }

        $data = json_decode($body, true);

        if (!\is_array($data)) {
            return $this->deny();
        }

        // Signed headers and duplicated body fields must agree exactly.
        if (!hash_equals($headers['requestId'], (string) ($data['request_id'] ?? ''))
            || !hash_equals($headers['nonce'], (string) ($data['nonce'] ?? ''))
            || $headers['timestamp'] !== (int) ($data['timestamp'] ?? -1)) {
            return $this->deny();
        }

        if ('license_update' !== (string) ($data['action'] ?? '')
            || !hash_equals($this->projectSlug, (string) ($data['project_slug'] ?? ''))
            || !hash_equals($this->productId, (string) ($data['product_id'] ?? ''))) {
            return $this->deny();
        }

        $bodyDomain = (string) ($data['domain'] ?? '');
        $fingerprint = $this->journal->fingerprint($body);

        // Idempotency: an exact authenticated retry returns the prior result without re-applying; a
        // reused id with different content is a security event.
        $prior = $this->journal->find($headers['requestId']);

        if (null !== $prior) {
            if (hash_equals($prior['fingerprint'], $fingerprint)) {
                return new JsonResponse([
                    'status' => 'already_processed',
                    'request_id' => $headers['requestId'],
                    'license_version' => $prior['version'],
                ]);
            }

            return $this->deny();
        }

        try {
            $version = $this->exchange->applyPush($data, $bodyDomain, $now);
        } catch (ExchangeException $e) {
            // Older/conflicting version, bad binding, or bad payload — all generic to the caller.
            return $this->deny();
        } catch (\Throwable) {
            return $this->deny();
        }

        $this->journal->record($headers['requestId'], $fingerprint, $version, $now);

        return new JsonResponse([
            'status' => 'updated',
            'request_id' => $headers['requestId'],
            'license_version' => $version,
        ]);
    }

    private function deny(): JsonResponse
    {
        return new JsonResponse(['status' => 'error', 'message' => 'Unauthorized.'], 401);
    }
}
