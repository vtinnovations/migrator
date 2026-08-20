<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Tests;

/**
 * Access to the APPROVED V-T.ONE signature vectors.
 *
 * The V-T.ONE signing keys are private to V-T.ONE, so this client cannot produce a positive vector
 * for the pinned ring by itself — and it must never fabricate one. Every positive end-to-end
 * verification test therefore reads a captured, approved response from tests/Fixture/ and is
 * SKIPPED (never silently passed) when that file is absent.
 *
 * To enable the positive matrix, drop the approved captured packets in:
 *
 *   tests/Fixture/activation-response.json   — a complete `POST /api/v1/verify` (action=activate)
 *                                              response body: status/request_id/server_time/
 *                                              license_payload_b64/integrity{...,signature}
 *   tests/Fixture/updater-request.json       — {"headers":{"X-VT-Request-ID":...,"X-VT-Timestamp":...,
 *                                              "X-VT-Nonce":...,"X-VT-Key-ID":...,"X-VT-Signature":...},
 *                                              "body":"<exact raw body bytes>"}
 *
 * These files contain licence material: keep them out of release artefacts (they live under tests/,
 * which the release build strips) and never echo their contents from a test.
 */
final class SignedFixture
{
    public const ACTIVATION = 'activation-response.json';
    public const UPDATER_REQUEST = 'updater-request.json';

    public static function dir(): string
    {
        return __DIR__.'/Fixture';
    }

    public static function has(string $name): bool
    {
        return is_file(self::dir().'/'.$name);
    }

    /**
     * @return array<string, mixed>
     */
    public static function load(string $name): array
    {
        $raw = file_get_contents(self::dir().'/'.$name);

        if (false === $raw) {
            throw new \RuntimeException(sprintf('Could not read fixture "%s".', $name));
        }

        $data = json_decode($raw, true);

        if (!\is_array($data)) {
            throw new \RuntimeException(sprintf('Fixture "%s" is not a JSON object.', $name));
        }

        return $data;
    }

    public static function missingMessage(string $name): string
    {
        return sprintf(
            'Approved V-T.ONE signature vector "%s" is not present in %s. Positive signature '
            .'verification cannot be proven without it, and must not be simulated.',
            $name,
            self::dir(),
        );
    }
}
