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
 * Outcome of a single step slice. A step returns one of these from run().
 *
 *  - continue: more slices needed; the SAME step re-runs next tick.
 *  - complete: step done; advance the job cursor to the next step.
 *  - pause:    block for user input (e.g. confirm URL map, resolve version mismatch).
 *  - fail:     unrecoverable; mark the job failed and trigger rollback.
 */
final class StepResult
{
    public const CONTINUE = 'continue';
    public const COMPLETE = 'complete';
    public const PAUSE = 'pause';
    public const FAIL = 'fail';

    private function __construct(
        public readonly string $type,
        public readonly string $message = '',
    ) {
    }

    public static function continueStep(string $message = ''): self
    {
        return new self(self::CONTINUE, $message);
    }

    public static function completeStep(string $message = ''): self
    {
        return new self(self::COMPLETE, $message);
    }

    public static function pause(string $message = ''): self
    {
        return new self(self::PAUSE, $message);
    }

    public static function fail(string $message = ''): self
    {
        return new self(self::FAIL, $message);
    }
}
