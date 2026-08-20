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

use Vtinnovations\Migrator\Config\Paths;
use Vtinnovations\Migrator\Security\TokenManager;

/**
 * Mode B pairing keys. The DESTINATION mints a single-use, time-boxed pairing: a random
 * session id plus a random secret. The operator carries the combined token to the SOURCE,
 * which uses the secret as the HMAC key for every pushed chunk.
 *
 * The raw secret is stored (0600, in the scratch dir) because the destination needs it to
 * verify incoming HMACs — it is short-lived, single-use, and invalidated the moment a
 * transfer finalizes. Pairings are pruned once expired or consumed.
 */
final class PairingStore
{
    private const DEFAULT_TTL = 3600; // 1 h — initial mint→first-push window

    public function __construct(
        private readonly Paths $paths,
        private readonly TokenManager $tokens,
    ) {
    }

    /**
     * Mint a pairing. Returns the parts the operator needs; the combined token to paste into
     * the source is "{session}.{key}".
     *
     * @return array{session:string, key:string, token:string, expiresAt:int}
     */
    public function mint(int $ttl = self::DEFAULT_TTL): array
    {
        $session = $this->tokens->randomToken(12);
        $key = $this->tokens->randomToken(32);
        $expiresAt = time() + max(60, $ttl);

        $all = $this->load();
        $all[$session] = [
            'key' => $key,
            'createdAt' => gmdate('c'),
            'expiresAt' => $expiresAt,
            'consumed' => false,
        ];
        $this->save($this->pruneArray($all));

        return [
            'session' => $session,
            'key' => $key,
            'token' => $session.'.'.$key,
            'expiresAt' => $expiresAt,
        ];
    }

    /**
     * Return a live (existing, unexpired, unconsumed) pairing, or null.
     *
     * @return array{key:string, createdAt:string, expiresAt:int, consumed:bool}|null
     */
    public function get(string $session): ?array
    {
        $all = $this->load();
        $pairing = $all[$session] ?? null;

        if (!\is_array($pairing)) {
            return null;
        }

        if (!empty($pairing['consumed']) || time() > (int) ($pairing['expiresAt'] ?? 0)) {
            return null;
        }

        return $pairing;
    }

    /**
     * Sliding-window keep-alive: extend a live pairing's expiry. Called on every authenticated
     * chunk so a large transfer that runs longer than the initial TTL does not expire mid-push.
     * No-op for missing/consumed/already-expired pairings (get() gates those out).
     */
    public function touch(string $session, int $ttl = self::DEFAULT_TTL): void
    {
        if (null === $this->get($session)) {
            return;
        }

        $all = $this->load();
        $all[$session]['expiresAt'] = time() + max(60, $ttl);
        $this->save($this->pruneArray($all));
    }

    public function consume(string $session): void
    {
        $all = $this->load();

        if (isset($all[$session])) {
            $all[$session]['consumed'] = true;
            $this->save($this->pruneArray($all));
        }
    }

    /**
     * Parse an operator-pasted "{session}.{key}" token into its parts.
     *
     * @return array{session:string, key:string}|null
     */
    public static function parseToken(string $token): ?array
    {
        $dot = strpos($token, '.');

        if (false === $dot) {
            return null;
        }

        $session = substr($token, 0, $dot);
        $key = substr($token, $dot + 1);

        if ('' === $session || '' === $key) {
            return null;
        }

        return ['session' => $session, 'key' => $key];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function load(): array
    {
        $file = $this->paths->pairingFile();

        if (!is_file($file)) {
            return [];
        }

        $raw = file_get_contents($file);
        $data = false !== $raw ? json_decode($raw, true) : null;

        return \is_array($data) ? $data : [];
    }

    /**
     * @param array<string, array<string, mixed>> $all
     */
    private function save(array $all): void
    {
        $file = $this->paths->pairingFile();
        $tmp = $file.'.tmp';
        $json = json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (false === $json || false === file_put_contents($tmp, $json, LOCK_EX) || !@rename($tmp, $file)) {
            @unlink($tmp);
            throw new \RuntimeException('Could not persist pairings.');
        }

        @chmod($file, 0600);
    }

    /**
     * Drop pairings that are consumed or expired beyond a small grace window.
     *
     * @param array<string, array<string, mixed>> $all
     *
     * @return array<string, array<string, mixed>>
     */
    private function pruneArray(array $all): array
    {
        $now = time();

        return array_filter($all, static function (array $p) use ($now): bool {
            if (!empty($p['consumed'])) {
                return false;
            }

            return $now <= (int) ($p['expiresAt'] ?? 0);
        });
    }
}
