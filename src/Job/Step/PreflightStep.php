<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Job\Step;

use Vtinnovations\Migrator\Job\Job;
use Vtinnovations\Migrator\Job\StepInterface;
use Vtinnovations\Migrator\Job\StepResult;
use Vtinnovations\Migrator\Manifest\Manifest;
use Vtinnovations\Migrator\Manifest\ManifestStore;
use Vtinnovations\Migrator\Preflight\ComposerAuditProbe;
use Vtinnovations\Migrator\Preflight\DiskSpaceProbe;
use Vtinnovations\Migrator\Preflight\EnvironmentProbe;
use Vtinnovations\Migrator\Preflight\RootPageScanner;
use Vtinnovations\Migrator\Preflight\UrlCandidateScanner;

/**
 * First export step. Captures environment + root pages + disk estimate into the manifest,
 * fails fast if disk is short, then scans the DB for source-host occurrences in
 * time-budgeted slices (resumable via a column offset in the job payload).
 */
final class PreflightStep implements StepInterface
{
    public function __construct(
        private readonly EnvironmentProbe $env,
        private readonly RootPageScanner $rootPages,
        private readonly DiskSpaceProbe $disk,
        private readonly UrlCandidateScanner $urls,
        private readonly ManifestStore $manifests,
        private readonly ComposerAuditProbe $composerAudit,
    ) {
    }

    public function name(): string
    {
        return 'preflight';
    }

    public function run(Job $job, float $deadline): StepResult
    {
        // Pre-send composer.json audit — pause ONCE so the operator can fix or consciously accept
        // portability risks BEFORE the package is built and shipped. Confirmed → never blocks again.
        if (empty($job->meta['composerAuditConfirmed'])) {
            $composerWarnings = $this->composerAudit->audit();

            if ([] !== $composerWarnings) {
                $job->meta['composerWarnings'] = $composerWarnings;

                return StepResult::pause(sprintf('composer.json needs review before sending — %d warning(s).', \count($composerWarnings)));
            }

            $job->meta['composerAuditConfirmed'] = true;
            unset($job->meta['composerWarnings']);
        }

        $manifest = $this->manifests->load($job->id) ?? new Manifest($job->id);
        $state = $job->payload['preflight'] ?? ['phase' => 'probe', 'offset' => 0];

        if ('probe' === $state['phase']) {
            $manifest->set('environment', $this->env->probe());

            $roots = $this->rootPages->scan();
            $manifest->set('urlReplacements', [
                'candidates' => [],
                'hasEmptyDns' => $roots['hasEmptyDns'],
                'hosts' => $roots['hosts'],
                'roots' => $roots['roots'],
            ]);

            $disk = $this->disk->probe();
            $manifest->set('disk', $disk);
            $this->manifests->save($job->id, $manifest);

            if (!$disk['sufficient']) {
                return StepResult::fail(sprintf(
                    'Insufficient disk space: need ~%d MiB, have %d MiB free.',
                    (int) ($disk['requiredBytes'] / 1048576),
                    (int) ($disk['freeBytes'] / 1048576),
                ));
            }

            // Cache the column list + hosts for the resumable scan phase.
            $job->payload['preflight'] = [
                'phase' => 'urlscan',
                'offset' => 0,
                'columns' => $this->urls->textColumns(),
                'hosts' => $roots['hosts'],
            ];

            return StepResult::continueStep(sprintf('Preflight: %d root host(s), scanning DB for URLs.', \count($roots['hosts'])));
        }

        // urlscan phase — resume from offset, honour the deadline.
        $columns = $state['columns'] ?? [];
        $hosts = $state['hosts'] ?? [];
        $offset = (int) ($state['offset'] ?? 0);
        $total = \count($columns);

        if (0 === $total || [] === $hosts) {
            return $this->finish($job, $manifest, 'No URL candidates to scan.');
        }

        $candidates = $manifest->get('urlReplacements')['candidates'] ?? [];

        while ($offset < $total && microtime(true) < $deadline) {
            $col = $columns[$offset];

            foreach ($hosts as $host) {
                $hit = $this->urls->inspectColumn($col['table'], $col['column'], $host);

                if ($hit['count'] > 0) {
                    $candidates[] = [
                        'table' => $col['table'],
                        'column' => $col['column'],
                        'pk' => $col['pk'],
                        'host' => $host,
                        'count' => $hit['count'],
                        'serialized' => $hit['serialized'],
                    ];
                }
            }

            ++$offset;
        }

        $url = $manifest->get('urlReplacements');
        $url['candidates'] = $candidates;
        $manifest->set('urlReplacements', $url);
        $this->manifests->save($job->id, $manifest);

        $job->payload['preflight'] = [
            'phase' => 'urlscan',
            'offset' => $offset,
            'columns' => $columns,
            'hosts' => $hosts,
        ];

        if ($offset >= $total) {
            return $this->finish($job, $manifest, sprintf('Preflight done: %d URL candidate column(s).', \count($candidates)));
        }

        return StepResult::continueStep(sprintf('URL scan %d/%d columns.', $offset, $total));
    }

    private function finish(Job $job, Manifest $manifest, string $msg): StepResult
    {
        unset($job->payload['preflight']);

        return StepResult::completeStep($msg);
    }
}
