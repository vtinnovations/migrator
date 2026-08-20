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

/**
 * A unit of migration work. Implementations are auto-tagged
 * "vtinnovations_migrator.step" via _instanceof and collected by StepRegistry.
 *
 * Steps MUST be resumable: read and write their resume position in $job->payload,
 * and stop work once $deadline (a Unix timestamp with fractional seconds) is reached,
 * returning continueStep() so the runner persists and resumes on the next tick.
 */
interface StepInterface
{
    /**
     * Stable identifier, referenced in a job's ordered step list. e.g. "preflight".
     */
    public function name(): string;

    /**
     * Run one time-budgeted slice of work.
     *
     * @param Job   $job      the job being advanced (mutate $job->payload for resume state)
     * @param float $deadline Unix timestamp (microtime float) after which the step should yield
     */
    public function run(Job $job, float $deadline): StepResult;
}
