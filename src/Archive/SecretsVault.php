<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Archive;

/**
 * Authenticated, passphrase-encrypted container for sensitive values that must travel with
 * a migration but never sit at rest in plaintext — APP_SECRET and the Contao encryption
 * key (so encrypted DB fields still decrypt on the destination).
 *
 * Prefers libsodium (Argon2id + XChaCha20-Poly1305); falls back to OpenSSL (PBKDF2-SHA256
 * + AES-256-GCM) when the sodium extension is unavailable. The blob is self-describing so
 * the destination picks the right decrypt path.
 */
final class SecretsVault
{
    private const MAGIC = "TCV1\n";
    private const METHOD_SODIUM = 'sodium';
    private const METHOD_OPENSSL = 'openssl';
    private const PBKDF2_ITERATIONS = 310000;

    /**
     * @param array<string, string> $secrets
     */
    public function encrypt(array $secrets, string $passphrase): string
    {
        $plaintext = json_encode($secrets, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        if (false === $plaintext) {
            throw new \RuntimeException('Failed to encode secrets.');
        }

        if ($this->hasSodium()) {
            return $this->encryptSodium($plaintext, $passphrase);
        }

        if (\extension_loaded('openssl')) {
            return $this->encryptOpenssl($plaintext, $passphrase);
        }

        throw new \RuntimeException('No crypto backend available (need sodium or openssl).');
    }

    /**
     * @return array<string, string>
     */
    public function decrypt(string $blob, string $passphrase): array
    {
        $payload = json_decode($this->stripMagic($blob), true);

        if (!\is_array($payload) || !isset($payload['method'])) {
            throw new \RuntimeException('Malformed secrets vault.');
        }

        $plaintext = match ($payload['method']) {
            self::METHOD_SODIUM => $this->decryptSodium($payload, $passphrase),
            self::METHOD_OPENSSL => $this->decryptOpenssl($payload, $passphrase),
            default => throw new \RuntimeException('Unknown vault method.'),
        };

        $secrets = json_decode($plaintext, true);

        if (!\is_array($secrets)) {
            throw new \RuntimeException('Vault decrypted but payload is not valid JSON (wrong passphrase?).');
        }

        return $secrets;
    }

    private function encryptSodium(string $plaintext, string $passphrase): string
    {
        $salt = random_bytes(SODIUM_CRYPTO_PWHASH_SALTBYTES);
        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $passphrase,
            $salt,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
        );
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $cipher = sodium_crypto_secretbox($plaintext, $nonce, $key);

        sodium_memzero($key);
        sodium_memzero($plaintext);

        return self::MAGIC.json_encode([
            'method' => self::METHOD_SODIUM,
            'salt' => base64_encode($salt),
            'nonce' => base64_encode($nonce),
            'cipher' => base64_encode($cipher),
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $p
     */
    private function decryptSodium(array $p, string $passphrase): string
    {
        if (!$this->hasSodium()) {
            throw new \RuntimeException('Vault needs the sodium extension to decrypt.');
        }

        $key = sodium_crypto_pwhash(
            SODIUM_CRYPTO_SECRETBOX_KEYBYTES,
            $passphrase,
            base64_decode((string) $p['salt'], true) ?: '',
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
        );

        $plaintext = sodium_crypto_secretbox_open(
            base64_decode((string) $p['cipher'], true) ?: '',
            base64_decode((string) $p['nonce'], true) ?: '',
            $key
        );
        sodium_memzero($key);

        if (false === $plaintext) {
            throw new \RuntimeException('Secrets vault authentication failed (wrong passphrase or corrupt data).');
        }

        return $plaintext;
    }

    private function encryptOpenssl(string $plaintext, string $passphrase): string
    {
        $salt = random_bytes(16);
        $key = hash_pbkdf2('sha256', $passphrase, $salt, self::PBKDF2_ITERATIONS, 32, true);
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);

        if (false === $cipher) {
            throw new \RuntimeException('OpenSSL encryption failed.');
        }

        return self::MAGIC.json_encode([
            'method' => self::METHOD_OPENSSL,
            'salt' => base64_encode($salt),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'cipher' => base64_encode($cipher),
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param array<string, mixed> $p
     */
    private function decryptOpenssl(array $p, string $passphrase): string
    {
        $key = hash_pbkdf2('sha256', $passphrase, base64_decode((string) $p['salt'], true) ?: '', self::PBKDF2_ITERATIONS, 32, true);

        $plaintext = openssl_decrypt(
            base64_decode((string) $p['cipher'], true) ?: '',
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            base64_decode((string) $p['iv'], true) ?: '',
            base64_decode((string) $p['tag'], true) ?: ''
        );

        if (false === $plaintext) {
            throw new \RuntimeException('Secrets vault authentication failed (wrong passphrase or corrupt data).');
        }

        return $plaintext;
    }

    private function stripMagic(string $blob): string
    {
        if (!str_starts_with($blob, self::MAGIC)) {
            throw new \RuntimeException('Not a Tahericreate secrets vault.');
        }

        return substr($blob, \strlen(self::MAGIC));
    }

    private function hasSodium(): bool
    {
        return \function_exists('sodium_crypto_secretbox') && \defined('SODIUM_CRYPTO_PWHASH_SALTBYTES');
    }
}
