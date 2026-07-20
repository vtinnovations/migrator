<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Security;

use Vtinnovations\Migrator\Config\Paths;

/**
 * Operator token (gates backend migrator actions) plus HMAC-SHA256 signing used for
 * manifests, archive chunks and Mode B transfer integrity.
 *
 * The operator token is generated once and stored at var/migrator/auth.token. HMAC
 * secrets are passed in explicitly (e.g. derived from a Mode B pairing key) so signing
 * keys never sit at rest alongside the data they protect.
 */
final class TokenManager
{
    public function __construct(private readonly Paths $paths)
    {
    }

    /**
     * Return the operator token, generating and persisting one on first use.
     */
    public function operatorToken(): string
    {
        $file = $this->paths->tokenFile();

        if (is_file($file)) {
            $token = trim((string) file_get_contents($file));

            if ('' !== $token) {
                return $token;
            }
        }

        $token = $this->randomToken();

        if (false === file_put_contents($file, $token, LOCK_EX)) {
            throw new \RuntimeException('Could not persist operator token.');
        }

        @chmod($file, 0600);

        return $token;
    }

    public function verifyOperatorToken(string $candidate): bool
    {
        return hash_equals($this->operatorToken(), $candidate);
    }

    public function rotateOperatorToken(): string
    {
        @unlink($this->paths->tokenFile());

        return $this->operatorToken();
    }

    /**
     * URL-safe random token. 32 bytes → 43-char base64url string.
     */
    public function randomToken(int $bytes = 32): string
    {
        return rtrim(strtr(base64_encode(random_bytes($bytes)), '+/', '-_'), '=');
    }

    public function sign(string $data, string $secret): string
    {
        return hash_hmac('sha256', $data, $secret);
    }

    public function verifySignature(string $data, string $signature, string $secret): bool
    {
        return hash_equals($this->sign($data, $secret), $signature);
    }
}
