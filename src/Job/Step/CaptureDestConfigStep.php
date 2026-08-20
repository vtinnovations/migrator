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

use Vtinnovations\Migrator\Config\ConfigReconciler;
use Vtinnovations\Migrator\Config\ConfigStore;
use Vtinnovations\Migrator\Config\EnvFileReader;
use Vtinnovations\Migrator\Job\Job;
use Vtinnovations\Migrator\Job\StepInterface;
use Vtinnovations\Migrator\Job\StepResult;

/**
 * Snapshots the destination-owned env values (DATABASE_URL, TRUSTED_HOSTS, …) BEFORE any
 * extract overwrites .env.local with the source's copy. The captured map is stashed on the
 * job and re-injected by ConfigReconcileStep after the source files have been laid down.
 *
 * MAILER_DSN is captured too when mailer_policy is 'keep' so the moved site keeps mailing
 * through the new host rather than the source's (possibly dead) mailer.
 */
final class CaptureDestConfigStep implements StepInterface
{
    public function __construct(
        private readonly ConfigReconciler $reconciler,
        private readonly EnvFileReader $env,
        private readonly ConfigStore $config,
    ) {
    }

    public function name(): string
    {
        return 'capture_dest_config';
    }

    public function run(Job $job, float $deadline): StepResult
    {
        $extra = [];

        if ('keep' === $this->config->get('mailer_policy', 'keep')) {
            $extra[] = 'MAILER_DSN';
        }

        $captured = $this->reconciler->captureDestination($this->env, $extra);

        $job->meta['destConfig'] = $captured;

        return StepResult::completeStep(sprintf('Captured %d destination config key(s).', \count($captured)));
    }
}
