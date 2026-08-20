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

use Vtinnovations\Migrator\Archive\SecretsVault;
use Vtinnovations\Migrator\Config\EnvFileReader;
use Vtinnovations\Migrator\Config\Paths;
use Vtinnovations\Migrator\Job\Job;
use Vtinnovations\Migrator\Job\StepInterface;
use Vtinnovations\Migrator\Job\StepResult;
use Vtinnovations\Migrator\Manifest\Manifest;
use Vtinnovations\Migrator\Manifest\ManifestStore;

/**
 * Captures the source APP_SECRET / encryption key and writes them to an encrypted
 * secrets/secrets.enc using a passphrase the operator supplied at export start (held in a
 * transient 0600 file, never in the job JSON). If no passphrase was provided the export
 * still completes, but the destination operator must re-enter these secrets by hand.
 *
 * Carrying these over matters: without the source APP_SECRET, Contao cannot decrypt
 * encrypted DB fields after the move.
 */
final class SecretsStep implements StepInterface
{
    private const SECRET_KEYS = ['APP_SECRET', 'CONTAO_ENCRYPTION_KEY', 'DATABASE_ENCRYPTION_KEY'];

    public function __construct(
        private readonly SecretsVault $vault,
        private readonly EnvFileReader $env,
        private readonly ManifestStore $manifests,
        private readonly Paths $paths,
    ) {
    }

    public function name(): string
    {
        return 'secrets';
    }

    public function run(Job $job, float $deadline): StepResult
    {
        $manifest = $this->manifests->load($job->id) ?? new Manifest($job->id);
        $secrets = $this->env->get(self::SECRET_KEYS);

        $passphraseFile = $this->paths->jobSecretFile($job->id);
        $passphrase = is_file($passphraseFile) ? trim((string) file_get_contents($passphraseFile)) : '';

        if ('' === $passphrase || [] === $secrets) {
            $manifest->set('secrets', [
                'present' => false,
                'reason' => '' === $passphrase ? 'no-passphrase' : 'no-secrets-found',
                'keys' => array_keys($secrets),
            ]);
            $this->manifests->save($job->id, $manifest);

            return StepResult::completeStep('Secrets step: nothing encrypted (destination must re-enter secrets).');
        }

        $dir = $this->paths->backupDir($job->id).'/secrets';

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return StepResult::fail('Could not create secrets dir.');
        }

        $blob = $this->vault->encrypt($secrets, $passphrase);

        if (false === file_put_contents($dir.'/secrets.enc', $blob, LOCK_EX)) {
            return StepResult::fail('Could not write secrets.enc.');
        }

        @chmod($dir.'/secrets.enc', 0600);

        // Passphrase is left in place for FinalizeExportStep (it signs the manifest with a
        // secret derived from the same passphrase) and burned there.

        $manifest->set('secrets', [
            'present' => true,
            'file' => 'secrets/secrets.enc',
            'keys' => array_keys($secrets),
            'sha256' => hash('sha256', $blob),
        ]);
        $this->manifests->save($job->id, $manifest);

        return StepResult::completeStep(sprintf('Encrypted %d secret(s).', \count($secrets)));
    }
}
