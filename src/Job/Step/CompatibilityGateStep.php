<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Job\Step;

use Vtinnovations\Migrator\Config\Paths;
use Vtinnovations\Migrator\Job\Job;
use Vtinnovations\Migrator\Job\StepInterface;
use Vtinnovations\Migrator\Job\StepResult;
use Vtinnovations\Migrator\Manifest\ManifestStore;
use Vtinnovations\Migrator\Preflight\EnvironmentProbe;

/**
 * Compares the source environment (from the manifest) with the destination. A PHP downgrade
 * or a Contao major-version difference pauses the job for explicit operator confirmation —
 * proceeding regardless is allowed but must be a conscious choice (set via meta on resume).
 */
final class CompatibilityGateStep implements StepInterface
{
    public function __construct(
        private readonly ManifestStore $manifests,
        private readonly EnvironmentProbe $env,
        private readonly Paths $paths,
    ) {
    }

    public function name(): string
    {
        return 'compat_gate';
    }

    public function run(Job $job, float $deadline): StepResult
    {
        $staging = (string) ($job->payload['staging'] ?? $this->paths->stagingDir($job->id));
        $manifest = $this->manifests->loadPath($staging.'/manifest.json');

        if (null === $manifest) {
            return StepResult::fail('Manifest missing for compatibility check.');
        }

        $source = (array) $manifest->get('environment', []);
        $dest = $this->env->probe();

        $warnings = [];

        if (isset($source['phpInt'], $dest['phpInt']) && $dest['phpInt'] < $source['phpInt']) {
            $warnings[] = sprintf('PHP downgrade: source %s → destination %s.', $source['php'] ?? '?', $dest['php'] ?? '?');
        }

        if (!empty($source['contao']) && !empty($dest['contao'])
            && $this->major((string) $source['contao']) !== $this->major((string) $dest['contao'])) {
            $warnings[] = sprintf('Contao major version differs: source %s → destination %s.', $source['contao'], $dest['contao']);
        }

        $job->meta['compatWarnings'] = $warnings;

        if ([] === $warnings) {
            return StepResult::completeStep('Compatibility OK.');
        }

        if (!empty($job->meta['compatConfirmed'])) {
            return StepResult::completeStep('Compatibility warnings acknowledged by operator.');
        }

        return StepResult::pause('Compatibility warnings — operator confirmation required: '.implode(' ', $warnings));
    }

    private function major(string $version): string
    {
        $version = ltrim($version, 'v');

        return explode('.', $version)[0] ?? $version;
    }
}
