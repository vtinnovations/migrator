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

namespace Vtinnovations\Migrator\Job;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Vtinnovations\Migrator\Config\EntitlementEvaluator;

/**
 * Advances a single job for one time budget. Holds the job lock for the whole tick so
 * concurrent ticks (cron + AJAX) cannot collide; persists between every slice so a
 * killed request loses at most the in-flight slice.
 */
final class JobRunner
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly JobStore $store,
        private readonly StepRegistry $registry,
        private readonly EntitlementEvaluator $entitlement,
        private readonly float $timeBudget = 20.0,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    /**
     * Advance the job until the time budget is spent, the job pauses/finishes/fails, or
     * the lock cannot be acquired. Returns the job's current state, or null if the job
     * was locked by another worker (caller should back off and retry).
     */
    public function tick(string $jobId): ?JobState
    {
        // Independent gate at the protected operation itself, not only at the request boundary:
        // migration work never advances without a valid signed licence, whichever entry point
        // (backend poll, cron, recovery panel) asked for the tick. The job is left untouched and
        // resumes unchanged once a licence is active again — nothing is deleted or failed.
        if (!$this->entitlement->evaluate()->isLicensed()) {
            $this->logger->warning('Migrator job {id} was not advanced: no valid licence.', ['id' => $jobId]);

            return $this->store->load($jobId)?->state;
        }

        $result = $this->store->withLock($jobId, function () use ($jobId): JobState {
            $job = $this->store->load($jobId);

            if (null === $job) {
                throw new \RuntimeException(sprintf('Job "%s" not found.', $jobId));
            }

            if ($job->state->isTerminal() || JobState::Paused === $job->state) {
                return $job->state;
            }

            $job->state = JobState::Running;
            $deadline = microtime(true) + $this->timeBudget;

            try {
                $this->advance($job, $deadline);
            } catch (\Throwable $e) {
                $job->state = JobState::Failed;
                $job->error = $e->getMessage(); // translated at display time
                $job->addLog($e->getMessage(), 'error');
                $this->logger->error('Migrator job {id} failed: {msg}', ['id' => $jobId, 'msg' => $e->getMessage()]);
            }

            $this->store->save($job);

            return $job->state;
        });

        return $result;
    }

    private function advance(Job $job, float $deadline): void
    {
        while (microtime(true) < $deadline) {
            if ($this->store->consumeCancel($job->id)) {
                $job->state = JobState::Cancelled;
                $job->error = 'Cancelled by operator.';
                $job->addLog('Cancelled by operator.', 'warning');

                return;
            }

            if ($job->isDone()) {
                $job->state = JobState::Completed;

                return;
            }

            $name = $job->currentStep();

            if (null === $name) {
                $job->state = JobState::Completed;

                return;
            }

            $step = $this->registry->get($name);
            $result = $step->run($job, $deadline);

            switch ($result->type) {
                case StepResult::COMPLETE:
                    if ('' !== $result->message) {
                        $job->addLog($result->message);
                    }
                    $job->advance();
                    break;

                case StepResult::CONTINUE:
                    // Same step re-runs. Persist this slice so a killed request loses at most
                    // the in-flight slice, then keep going until the time budget is spent —
                    // otherwise a fast slice (e.g. one 50 MiB archive chunk) would return after
                    // a fraction of the budget and waste the rest waiting for the next poll.
                    if ('' !== $result->message) {
                        $job->addLog($result->message, 'debug');
                    }
                    $this->store->save($job);
                    break;

                case StepResult::PAUSE:
                    $job->state = JobState::Paused;
                    $job->addLog($result->message ?: sprintf('Paused at step "%s".', $name), 'warning');

                    return;

                case StepResult::FAIL:
                    $job->state = JobState::Failed;
                    $job->error = $result->message; // translated at display time
                    $job->addLog($result->message, 'error');

                    return;
            }
        }
    }
}
