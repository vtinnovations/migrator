<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Job\Step;

use Vtinnovations\Migrator\Archive\SecretsVault;
use Vtinnovations\Migrator\Config\ConfigReconciler;
use Vtinnovations\Migrator\Config\Paths;
use Vtinnovations\Migrator\Job\Job;
use Vtinnovations\Migrator\Job\StepInterface;
use Vtinnovations\Migrator\Job\StepResult;
use Vtinnovations\Migrator\Manifest\ManifestStore;

/**
 * Repairs .env.local after the source files were laid over it: restores the destination-
 * owned keys captured before extract (DATABASE_URL, TRUSTED_HOSTS, …) and injects the
 * source's carried secrets (APP_SECRET, encryption keys) so encrypted DB fields still
 * decrypt on the new host.
 *
 * Running this is mandatory even with no secrets vault — otherwise the moved site would
 * boot pointing at the SOURCE database (whatever DATABASE_URL the source's .env.local held).
 */
final class ConfigReconcileStep implements StepInterface
{
    public function __construct(
        private readonly ConfigReconciler $reconciler,
        private readonly SecretsVault $vault,
        private readonly ManifestStore $manifests,
        private readonly Paths $paths,
    ) {
    }

    public function name(): string
    {
        return 'config_reconcile';
    }

    public function run(Job $job, float $deadline): StepResult
    {
        $staging = (string) ($job->payload['staging'] ?? $this->paths->stagingDir($job->id));
        $manifest = $this->manifests->loadPath($staging.'/manifest.json');

        if (null === $manifest) {
            return StepResult::fail('Manifest missing for config reconcile.');
        }

        /** @var array<string,string> $destConfig */
        $destConfig = $job->meta['destConfig'] ?? [];
        $sourceSecrets = [];
        $note = '';

        $secrets = (array) $manifest->get('secrets', []);

        if (!empty($secrets['present'])) {
            $passphraseFile = $this->paths->jobSecretFile($job->id);
            $passphrase = is_file($passphraseFile) ? trim((string) file_get_contents($passphraseFile)) : '';

            if ('' === $passphrase) {
                $note = ' Carried secrets skipped (no passphrase) — encrypted DB fields may be unreadable.';
            } else {
                $blob = (string) file_get_contents($staging.'/'.($secrets['file'] ?? 'secrets/secrets.enc'));

                try {
                    $sourceSecrets = $this->vault->decrypt($blob, $passphrase);
                } catch (\Throwable $e) {
                    return StepResult::fail('Could not decrypt secrets vault: '.$e->getMessage());
                }
            }
        }

        $this->reconciler->apply($destConfig, $sourceSecrets);

        return StepResult::completeStep(sprintf(
            'Config reconciled: %d destination key(s) restored, %d source secret(s) carried.%s',
            \count($destConfig),
            \count($sourceSecrets),
            $note
        ));
    }
}
