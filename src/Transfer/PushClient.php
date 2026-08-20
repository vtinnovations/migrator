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

use Vtinnovations\Migrator\Security\TokenManager;

/**
 * Source-side Mode B transport: POSTs signed payload chunks to a destination's ingest
 * endpoints. Each chunk is authenticated with an HMAC over the pairing key so the remote can
 * reject anything not pushed by a paired source.
 *
 * Uses ext-curl when available (reliable binary POST) and falls back to a stream-context
 * request so the bundle still works on hosts without curl.
 */
final class PushClient
{
    public function __construct(private readonly TokenManager $tokens)
    {
    }

    /**
     * @return array<string, mixed> decoded JSON response
     */
    public function pushChunk(string $baseUrl, string $session, string $key, int $index, string $bytes): array
    {
        $sig = $this->tokens->sign($session.'|'.$index.'|'.hash('sha256', $bytes), $key);

        return $this->request(
            $this->endpoint($baseUrl, $session),
            $bytes,
            [
                'Content-Type: application/octet-stream',
                'X-Tcmig-Index: '.$index,
                'X-Tcmig-Signature: '.$sig,
            ]
        );
    }

    /**
     * @return array<string, mixed> decoded JSON response (includes jobId on success)
     */
    public function finalize(string $baseUrl, string $session, string $key, int $count, string $sha): array
    {
        $sig = $this->tokens->sign($session.'|finalize|'.$count.'|'.$sha, $key);

        return $this->request(
            $this->endpoint($baseUrl, $session).'/finalize',
            '',
            [
                'Content-Type: application/octet-stream',
                'X-Tcmig-Count: '.$count,
                'X-Tcmig-Sha: '.$sha,
                'X-Tcmig-Signature: '.$sig,
            ]
        );
    }

    private function endpoint(string $baseUrl, string $session): string
    {
        return rtrim($baseUrl, '/').'/migrator/ingest/'.rawurlencode($session);
    }

    /**
     * @param list<string> $headers
     *
     * @return array<string, mixed>
     */
    private function request(string $url, string $body, array $headers): array
    {
        [$status, $responseBody] = \function_exists('curl_init')
            ? $this->curl($url, $body, $headers)
            : $this->stream($url, $body, $headers);

        $decoded = json_decode($responseBody, true);

        if ($status < 200 || $status >= 300) {
            $msg = \is_array($decoded) && isset($decoded['error']) ? (string) $decoded['error'] : 'HTTP '.$status;

            // 413 is recoverable: the push step shrinks the chunk and retries. A 413 body is
            // usually the web server's own HTML (not JSON), so key off the status code.
            if (413 === $status) {
                throw new PayloadTooLargeException('Push rejected by destination: '.$msg);
            }

            throw new \RuntimeException('Push rejected by destination: '.$msg);
        }

        return \is_array($decoded) ? $decoded : [];
    }

    /**
     * @param list<string> $headers
     *
     * @return array{0:int, 1:string}
     */
    private function curl(string $url, string $body, array $headers): array
    {
        $ch = curl_init($url);

        if (false === $ch) {
            throw new \RuntimeException('Could not initialise curl.');
        }

        curl_setopt_array($ch, [
            \CURLOPT_POST => true,
            \CURLOPT_POSTFIELDS => $body,
            \CURLOPT_HTTPHEADER => $headers,
            \CURLOPT_RETURNTRANSFER => true,
            \CURLOPT_CONNECTTIMEOUT => 15,
            \CURLOPT_TIMEOUT => 300,
            \CURLOPT_SSL_VERIFYPEER => true,
            \CURLOPT_SSL_VERIFYHOST => 2,
            // Follow a canonical redirect (non-www→www, http→https) but KEEP it a POST with the
            // same body/headers — otherwise a destination that enforces a canonical host answers
            // 301 and the push is wrongly rejected. Bounded, and SSL verification stays on.
            \CURLOPT_FOLLOWLOCATION => true,
            \CURLOPT_MAXREDIRS => 3,
            \CURLOPT_POSTREDIR => \CURL_REDIR_POST_301 | \CURL_REDIR_POST_302 | \CURL_REDIR_POST_303,
        ]);

        $response = curl_exec($ch);
        $status = (int) curl_getinfo($ch, \CURLINFO_RESPONSE_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if (false === $response) {
            throw new \RuntimeException('Push transport error: '.$error);
        }

        return [$status, (string) $response];
    }

    /**
     * @param list<string> $headers
     *
     * @return array{0:int, 1:string}
     */
    private function stream(string $url, string $body, array $headers): array
    {
        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => implode("\r\n", $headers),
                'content' => $body,
                'timeout' => 300,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => true,
                'verify_peer_name' => true,
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $status = 0;

        // $http_response_header is populated by the stream wrapper in this scope.
        foreach ($http_response_header ?? [] as $line) {
            if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $m)) {
                $status = (int) $m[1];
            }
        }

        if (false === $response) {
            throw new \RuntimeException('Push transport error (stream).');
        }

        return [$status, (string) $response];
    }
}
