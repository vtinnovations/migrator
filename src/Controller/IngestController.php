<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Controller;

use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Vtinnovations\Migrator\Config\EntitlementEvaluator;
use Vtinnovations\Migrator\Job\JobFactory;
use Vtinnovations\Migrator\Security\TokenManager;
use Vtinnovations\Migrator\Transfer\ChunkAssembler;
use Vtinnovations\Migrator\Transfer\PairingStore;

/**
 * Mode B receive endpoints. Public (the source server posts here without a backend login) but
 * every request is authenticated by an HMAC computed with the single-use pairing key minted
 * by this destination — so only a source holding the operator-carried token can push.
 *
 *   POST /migrator/ingest/{session}            one signed payload chunk
 *   POST /migrator/ingest/{session}/finalize   assemble + verify + enqueue an import job
 *
 * Per-chunk signature  = HMAC(key, "{session}|{index}|{sha256(body)}")
 * Finalize signature   = HMAC(key, "{session}|finalize|{count}|{sha256(whole payload)}")
 */
final class IngestController extends AbstractController
{
    public function __construct(
        private readonly PairingStore $pairings,
        private readonly ChunkAssembler $assembler,
        private readonly TokenManager $tokens,
        private readonly JobFactory $jobs,
        private readonly EntitlementEvaluator $license,
    ) {
    }

    public function chunk(Request $request, string $session): JsonResponse
    {
        if (!$this->license->evaluate()->isLicensed()) {
            return $this->deny('License required on destination.');
        }

        $pairing = $this->pairings->get($session);

        if (null === $pairing) {
            return $this->deny('Unknown, expired or consumed pairing.');
        }

        $index = (int) $request->headers->get('X-Tcmig-Index', '-1');
        $signature = (string) $request->headers->get('X-Tcmig-Signature', '');
        $body = $request->getContent();

        if ($index < 0) {
            return new JsonResponse(['error' => 'Missing chunk index.'], 400);
        }

        $payload = $session.'|'.$index.'|'.hash('sha256', $body);

        if (!$this->tokens->verifySignature($payload, $signature, (string) $pairing['key'])) {
            return $this->deny('Bad chunk signature.');
        }

        // Sliding window: a multi-GB push can run longer than the initial TTL. Each authenticated
        // chunk extends the pairing's expiry so it stays valid for the duration of the transfer.
        $this->pairings->touch($session);

        $written = $this->assembler->append($session, $index, $body);
        $state = $this->assembler->state($session);

        return new JsonResponse([
            'ok' => true,
            'index' => $index,
            'stored' => $written,
            'chunks' => $state['count'],
            'bytes' => $state['bytes'],
        ]);
    }

    public function finalize(Request $request, string $session): JsonResponse
    {
        if (!$this->license->evaluate()->isLicensed()) {
            return $this->deny('License required on destination.');
        }

        $pairing = $this->pairings->get($session);

        if (null === $pairing) {
            return $this->deny('Unknown, expired or consumed pairing.');
        }

        $count = (int) $request->headers->get('X-Tcmig-Count', '0');
        $sha = (string) $request->headers->get('X-Tcmig-Sha', '');
        $signature = (string) $request->headers->get('X-Tcmig-Signature', '');

        if ($count <= 0 || '' === $sha) {
            return new JsonResponse(['error' => 'Missing finalize count or checksum.'], 400);
        }

        $payload = $session.'|finalize|'.$count.'|'.$sha;

        if (!$this->tokens->verifySignature($payload, $signature, (string) $pairing['key'])) {
            return $this->deny('Bad finalize signature.');
        }

        try {
            $archive = $this->assembler->assemble($session, $count, $sha);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        // One-shot: the pairing cannot be reused once a payload has landed.
        $this->pairings->consume($session);
        $this->assembler->discardChunks($session);

        $job = $this->jobs->createImport([
            'mode' => 'B',
            'session' => $session,
            'archive' => $archive,
        ]);

        return new JsonResponse([
            'ok' => true,
            'jobId' => $job->id,
            'archive' => basename($archive),
        ]);
    }

    private function deny(string $message): JsonResponse
    {
        return new JsonResponse(['error' => $message], 403);
    }
}
