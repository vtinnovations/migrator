<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Controller;

use Contao\CoreBundle\Csrf\ContaoCsrfTokenManager;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Security\Csrf\CsrfToken;
use Vtinnovations\Migrator\Config\ConfigStore;
use Vtinnovations\Migrator\Job\JobFactory;
use Vtinnovations\Migrator\Job\JobRunner;
use Vtinnovations\Migrator\Job\JobStore;
use Vtinnovations\Migrator\Security\LicenseGuard;
use Vtinnovations\Migrator\Support\Messages;
use Vtinnovations\Migrator\Transfer\ChunkAssembler;
use Vtinnovations\Migrator\Transfer\PackageBuilder;

/**
 * Backend AJAX + download endpoints behind the /contao firewall. The dashboard polls status()
 * which also DRIVES the job: each poll runs one time-budgeted tick, so the pipeline advances
 * purely from the operator's open browser — no cron required (a cron may also tick the same
 * job; the job lock keeps them from colliding).
 */
final class MigratorController extends AbstractController
{
    public function __construct(
        private readonly JobRunner $runner,
        private readonly JobStore $store,
        private readonly PackageBuilder $builder,
        private readonly ConfigStore $config,
        private readonly LicenseGuard $license,
        private readonly ChunkAssembler $assembler,
        private readonly JobFactory $jobs,
        private readonly ContaoCsrfTokenManager $csrf,
        private readonly string $csrfTokenName,
    ) {
    }

    /**
     * Read-only status for the dashboard poller. Deliberately does NOT advance the job: a tick
     * can run for the full time budget and would hold the PHP session lock that whole time,
     * stalling the UI (the bar would only jump every ~20s / on tab switch). Driving happens in
     * tick() and via the background cron.
     */
    public function status(Request $request, string $id): JsonResponse
    {
        if (!$this->license->isLicensed()) {
            return $this->license->noLicenseResponsePaidOnly();
        }

        $job = $this->store->load($id);

        if (null === $job) {
            return new JsonResponse(['error' => 'Job not found.'], 404);
        }

        $tail = \array_slice($job->log, -12);
        $lang = str_starts_with((string) ($GLOBALS['TL_LANGUAGE'] ?? ''), 'de') ? 'de' : 'en';
        $step = $job->currentStep();

        return new JsonResponse([
            'id' => $job->id,
            'type' => $job->type->value,
            'state' => $job->state->value,
            'stateLabel' => Messages::stateLabel($lang, $job->state->value),
            'progress' => $job->progress(),
            'cursor' => $job->cursor,
            'step' => $step,
            'stepLabel' => null !== $step ? Messages::stepLabel($lang, $step) : '',
            'steps' => $job->steps,
            'error' => null !== $job->error ? Messages::translate($lang, $job->error) : null,
            'meta' => [
                'mode' => $job->meta['mode'] ?? null,
                'download' => $job->meta['download'] ?? null,
                'compatWarnings' => $job->meta['compatWarnings'] ?? [],
                'remoteJobId' => $job->meta['remoteJobId'] ?? null,
                'importComplete' => $job->meta['importComplete'] ?? false,
            ],
            'log' => array_map(static fn (array $l): array => [
                'level' => $l['level'] ?? 'info',
                'msg' => Messages::translate($lang, (string) ($l['msg'] ?? '')),
            ], $tail),
        ]);
    }

    /**
     * Advances the job by one time budget. Called in its own JS loop, separate from the status
     * poll, so a long tick never delays the UI. The session lock is released up front: the
     * backend firewall has already authenticated this request, and releasing the lock lets the
     * concurrent (fast) status polls proceed instead of queuing behind the ~20s tick.
     */
    public function tick(Request $request, string $id): JsonResponse
    {
        if (!$this->license->isLicensed()) {
            return $this->license->noLicenseResponsePaidOnly();
        }

        if (\PHP_SESSION_ACTIVE === session_status()) {
            @session_write_close();
        }

        @set_time_limit(0);

        $state = $this->runner->tick($id); // null = another worker (cron/other poll) holds the lock
        $job = $this->store->load($id);

        if (null === $job) {
            return new JsonResponse(['error' => 'Job not found.'], 404);
        }

        return new JsonResponse([
            'state' => $job->state->value,
            'locked' => null === $state,
            'progress' => $job->progress(),
            'terminal' => $job->state->isTerminal(),
        ]);
    }

    public function download(Request $request, string $id): Response
    {
        if (!$this->license->isLicensed()) {
            throw $this->createAccessDeniedException('License required.');
        }

        $job = $this->store->load($id);

        if (null === $job) {
            throw $this->createNotFoundException('Job not found.');
        }

        $ready = $job->meta['download']['ready'] ?? false;

        if (!$ready) {
            throw $this->createNotFoundException('Package is not ready for download.');
        }

        // Building/streaming a multi-GB package can take well over the default execution time;
        // this is a plain download request (not a budgeted job tick), so lift the limit.
        @set_time_limit(0);

        $path = $this->builder->build($id); // cached after first call
        $size = (int) filesize($path);
        $filename = (string) ($job->meta['download']['filename'] ?? ('contao-migration-'.substr($id, 0, 8).'.tcmig'));

        // Operator-chosen part size wins: ?vol=<MB> (min 1 MiB). Falls back to the configured
        // default. Slicing the cached .tcmig means any size works — concatenating parts in order
        // reproduces the file regardless of where the cuts fall.
        $volMb = $request->query->getInt('vol', 0);
        $volume = $volMb > 0 ? max(1, $volMb) * 1048576 : (int) $this->config->get('download_volume_bytes', 0);
        $parts = ($volume > 0 && $size > $volume) ? (int) ceil($size / $volume) : 1;

        // ?meta=1 — cheap info for the dashboard to render the right number of part links.
        if ($request->query->getBoolean('meta')) {
            return new JsonResponse([
                'bytes' => $size,
                'parts' => $parts,
                'volumeBytes' => $volume,
                'filename' => $filename,
            ]);
        }

        // Whole-file download when splitting is off, the package fits one volume, or no part asked.
        if ($parts <= 1 || !$request->query->has('part')) {
            $response = new BinaryFileResponse($path);
            $response->setContentDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename);
            $response->headers->set('Content-Type', 'application/octet-stream');

            return $response;
        }

        // Stream one raw byte slice. Concatenating all parts in order reproduces the .tcmig.
        $part = $request->query->getInt('part');

        if ($part < 0 || $part >= $parts) {
            throw $this->createNotFoundException('Part out of range.');
        }

        $offset = $part * $volume;
        $length = min($volume, $size - $offset);
        $partName = sprintf('%s.%03d', $filename, $part + 1);

        $response = new StreamedResponse(static function () use ($path, $offset, $length): void {
            $fh = fopen($path, 'rb');

            if (false === $fh) {
                return;
            }

            try {
                fseek($fh, $offset);
                $remaining = $length;

                while ($remaining > 0 && !feof($fh)) {
                    $buf = fread($fh, (int) min(1048576, $remaining));

                    if (false === $buf || '' === $buf) {
                        break;
                    }

                    echo $buf;
                    $remaining -= \strlen($buf);
                    flush();
                }
            } finally {
                fclose($fh);
            }
        });

        $response->headers->set('Content-Type', 'application/octet-stream');
        $response->headers->set('Content-Length', (string) $length);
        $response->headers->set(
            'Content-Disposition',
            $response->headers->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $partName)
        );

        return $response;
    }

    /**
     * Store one uploaded split volume as chunk {index} of an import session. Called sequentially
     * by the dashboard's JS uploader (one small part per request), so no single request carries
     * the whole package — sidestepping post_max_size / execution-time limits on big migrations.
     */
    public function uploadPart(Request $request, string $isess): JsonResponse
    {
        if (!$this->license->isLicensed()) {
            return $this->license->noLicenseResponsePaidOnly();
        }

        if (!$this->validToken($request)) {
            return $this->jerr('Invalid request token.', 403);
        }

        $index = $request->request->getInt('index', -1);
        $file = $request->files->get('part');

        if ($index < 0) {
            return $this->jerr('Missing part index.', 400);
        }

        if (null === $file || !$file->isValid()) {
            return $this->jerr('No valid part uploaded.', 400);
        }

        @set_time_limit(0);
        $this->assembler->appendFile($isess, $index, $file->getPathname());
        $state = $this->assembler->state($isess);

        return new JsonResponse(['ok' => true, 'index' => $index, 'count' => $state['count'], 'bytes' => $state['bytes']]);
    }

    /**
     * Reassemble the uploaded parts of an import session into one .tcmig and enqueue the import.
     * Returns the new job id so the dashboard can jump to its monitor.
     */
    public function assembleImport(Request $request, string $isess): JsonResponse
    {
        if (!$this->license->isLicensed()) {
            return $this->license->noLicenseResponsePaidOnly();
        }

        if (!$this->validToken($request)) {
            return $this->jerr('Invalid request token.', 403);
        }

        $count = $this->assembler->state($isess)['count'];

        if ($count < 1) {
            return $this->jerr('No parts uploaded.', 400);
        }

        @set_time_limit(0);

        try {
            // No whole-payload sha for a local upload — the import pipeline verifies the manifest
            // and per-chunk checksums inside the package anyway.
            $archive = $this->assembler->assemble($isess, $count);
        } catch (\Throwable $e) {
            return $this->jerr($e->getMessage(), 422);
        }

        $this->assembler->discardChunks($isess);

        $passphrase = (string) $request->request->get('passphrase', '');
        $job = $this->jobs->createImport(['archive' => $archive], '' !== $passphrase ? $passphrase : null);

        return new JsonResponse(['ok' => true, 'jobId' => $job->id]);
    }

    /** Request cancellation of a running/pending/paused job (stops it mid-tick). */
    public function cancel(Request $request, string $id): JsonResponse
    {
        if (!$this->license->isLicensed()) {
            return $this->license->noLicenseResponsePaidOnly();
        }

        if (!$this->validToken($request)) {
            return $this->jerr('Invalid request token.', 403);
        }

        $this->store->requestCancel($id);

        return new JsonResponse(['ok' => true]);
    }

    /** Delete a finished/failed/cancelled job and all its artefacts. Refuses while still active. */
    public function delete(Request $request, string $id): JsonResponse
    {
        if (!$this->license->isLicensed()) {
            return $this->license->noLicenseResponsePaidOnly();
        }

        if (!$this->validToken($request)) {
            return $this->jerr('Invalid request token.', 403);
        }

        $job = $this->store->load($id);

        if (null !== $job && !$job->state->isTerminal()) {
            return $this->jerr('Cancel the job before deleting it.', 409);
        }

        $this->store->delete($id);

        return new JsonResponse(['ok' => true]);
    }

    private function validToken(Request $request): bool
    {
        return $this->csrf->isTokenValid(
            new CsrfToken($this->csrfTokenName, (string) $request->request->get('REQUEST_TOKEN', ''))
        );
    }

    /** JSON error localised to the backend user's language. */
    private function jerr(string $msg, int $code): JsonResponse
    {
        $lang = str_starts_with((string) ($GLOBALS['TL_LANGUAGE'] ?? ''), 'de') ? 'de' : 'en';

        return new JsonResponse(['error' => Messages::translate($lang, $msg)], $code);
    }
}
