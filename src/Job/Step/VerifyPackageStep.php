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

namespace Vtinnovations\Migrator\Job\Step;

use Vtinnovations\Migrator\Config\Paths;
use Vtinnovations\Migrator\Job\Job;
use Vtinnovations\Migrator\Job\StepInterface;
use Vtinnovations\Migrator\Job\StepResult;
use Vtinnovations\Migrator\Manifest\ManifestStore;
use Vtinnovations\Migrator\Security\TokenManager;

/**
 * Verifies package integrity before anything destructive happens: the manifest HMAC
 * signature (using a secret derived from the import passphrase, or the operator token) and
 * the sha256 of every db/file chunk against the manifest. Any mismatch fails the job.
 */
final class VerifyPackageStep implements StepInterface
{
    public function __construct(
        private readonly ManifestStore $manifests,
        private readonly TokenManager $tokens,
        private readonly Paths $paths,
    ) {
    }

    public function name(): string
    {
        return 'verify_package';
    }

    public function run(Job $job, float $deadline): StepResult
    {
        $staging = (string) ($job->payload['staging'] ?? $this->paths->stagingDir($job->id));
        $manifest = $this->manifests->loadPath($staging.'/manifest.json');

        if (null === $manifest) {
            return StepResult::fail('Manifest missing in staging.');
        }

        $passphraseFile = $this->paths->jobSecretFile($job->id);
        $passphrase = is_file($passphraseFile) ? trim((string) file_get_contents($passphraseFile)) : '';
        $signedWith = $manifest->get('signedWith');

        $secret = 'passphrase' === $signedWith
            ? hash('sha256', 'tcmig-sign|'.$passphrase)
            : $this->tokens->operatorToken();

        // Missing or wrong passphrase is recoverable: pause and let the operator supply it from
        // the monitor (a re-arm re-runs this step), rather than killing the whole upload.
        if ('passphrase' === $signedWith && '' === $passphrase) {
            $job->meta['needsPassphrase'] = true;

            return StepResult::pause('This package is passphrase-signed — supply the export passphrase to verify it.');
        }

        if (!$manifest->verify(fn (string $payload, string $sig): bool => $this->tokens->verifySignature($payload, $sig, $secret))) {
            if ('passphrase' === $signedWith) {
                $job->meta['needsPassphrase'] = true;

                return StepResult::pause('Manifest signature invalid — wrong passphrase. Re-enter the export passphrase.');
            }

            return StepResult::fail('Manifest signature invalid — tampered package.');
        }

        unset($job->meta['needsPassphrase']);

        // Verify chunk checksums.
        $resume = (int) ($job->payload['verifyOffset'] ?? 0);
        $chunks = $this->collectChunks($manifest);
        $total = \count($chunks);

        while ($resume < $total && microtime(true) < $deadline) {
            $chunk = $chunks[$resume];
            $abs = $staging.'/'.$chunk['file'];

            if (!is_file($abs)) {
                return StepResult::fail('Missing chunk: '.$chunk['file']);
            }

            if (hash_file('sha256', $abs) !== $chunk['sha256']) {
                return StepResult::fail('Checksum mismatch: '.$chunk['file']);
            }

            ++$resume;
        }

        $job->payload['verifyOffset'] = $resume;

        if ($resume < $total) {
            return StepResult::continueStep(sprintf('Verified %d/%d chunks.', $resume, $total));
        }

        unset($job->payload['verifyOffset']);

        return StepResult::completeStep(sprintf('Package verified (%d chunks, signature OK).', $total));
    }

    /**
     * @return list<array{file:string, sha256:string}>
     */
    private function collectChunks(\Vtinnovations\Migrator\Manifest\Manifest $manifest): array
    {
        $out = [];

        foreach (($manifest->get('database')['tables'] ?? []) as $info) {
            foreach (($info['chunks'] ?? []) as $chunk) {
                if (isset($chunk['file'], $chunk['sha256'])) {
                    $out[] = ['file' => $chunk['file'], 'sha256' => $chunk['sha256']];
                }
            }
        }

        foreach (($manifest->get('files')['chunks'] ?? []) as $chunk) {
            if (isset($chunk['file'], $chunk['sha256'])) {
                $out[] = ['file' => $chunk['file'], 'sha256' => $chunk['sha256']];
            }
        }

        return $out;
    }
}
