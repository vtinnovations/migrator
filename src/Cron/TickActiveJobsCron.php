<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Cron;

use Vtinnovations\Migrator\Job\JobRunner;
use Vtinnovations\Migrator\Job\JobState;
use Vtinnovations\Migrator\Job\JobStore;

/**
 * Advances every non-terminal job by one time-budgeted tick on each cron run, so a migration
 * keeps progressing even when no operator browser is polling the dashboard (closed tab, logout).
 *
 * The job lock means this never collides with a concurrent dashboard poll: whichever holds the
 * lock ticks, the other backs off. When the dashboard IS open it drives the job far faster
 * (a tick every poll); this cron is the unattended floor.
 *
 * Registered as a 'minutely' Contao cronjob. For real throughput on shared hosting, point a
 * system cron at `vendor/bin/contao-console contao:cron` every minute (Contao's web-cron only
 * fires on frontend traffic, which a backend-only migration may not generate).
 */
final class TickActiveJobsCron
{
    public function __construct(
        private readonly JobRunner $runner,
        private readonly JobStore $store,
    ) {
    }

    public function __invoke(): void
    {
        foreach ($this->store->listIds() as $id) {
            $job = $this->store->load($id);

            if (null === $job || $job->state->isTerminal() || JobState::Paused === $job->state) {
                continue;
            }

            // One budgeted tick. tick() returns null if another worker holds the lock — fine,
            // that worker is making progress; we just move on.
            $this->runner->tick($id);
        }
    }
}
