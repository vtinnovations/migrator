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

namespace Vtinnovations\Migrator\Transfer;

use Vtinnovations\Migrator\Manifest\DetachedVerifier;
use Vtinnovations\Migrator\Manifest\TrustRing;

/**
 * Authenticates an inbound server-initiated updater request under the fixed `vt-one/request-sig-v1`
 * scheme. The signed message is six `\n`-joined lines — uppercased method, exact served path,
 * request id, decimal timestamp, nonce, lowercase-hex SHA-256 of the raw body — and X-VT-Key-ID
 * selects the pinned request-purpose key (it is deliberately NOT part of the signed message).
 *
 * A claimed web origin proves nothing; only this signature does. Everything fails closed.
 */
final class UpdaterRequestVerifier
{
    private const MAX_SKEW = 300;

    public function __construct(
        private readonly TrustRing $ring,
        private readonly DetachedVerifier $verifier,
    ) {
    }

    /**
     * @param array{requestId:string, timestamp:int, nonce:string, keyId:string, signature:string} $h
     */
    public function verify(string $method, string $path, array $h, string $rawBody, int $now): bool
    {
        if ('' === $h['signature'] || '' === $h['keyId'] || '' === $h['requestId'] || '' === $h['nonce']) {
            return false;
        }

        if (abs($now - $h['timestamp']) > self::MAX_SKEW) {
            return false;
        }

        $rawKey = $this->ring->rawKey($h['keyId'], TrustRing::PURPOSE_REQUEST, $now);

        if (null === $rawKey) {
            return false;
        }

        $message = implode("\n", [
            strtoupper($method),
            $path,
            $h['requestId'],
            (string) $h['timestamp'],
            $h['nonce'],
            hash('sha256', $rawBody),
        ]);

        return $this->verifier->verify($message, $h['signature'], $rawKey);
    }
}
