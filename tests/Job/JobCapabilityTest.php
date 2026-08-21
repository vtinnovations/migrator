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

namespace Vtinnovations\Migrator\Tests\Job;

use PHPUnit\Framework\TestCase;
use Vtinnovations\Migrator\Config\EntitlementState;
use Vtinnovations\Migrator\Job\Job;
use Vtinnovations\Migrator\Job\JobType;

/**
 * Which tier a job's own work belongs to.
 *
 * This classification is what stops a Mode B push from advancing on a Free licence, and it is
 * consulted from three independent entry points (dashboard poll, cron, recovery panel), so all
 * three must agree. Mode B is recorded in the job's metadata at creation time — a push is an
 * Export job flagged `mode => B`, which is exactly why the job type alone is not enough to tell.
 */
final class JobCapabilityTest extends TestCase
{
    public function testManualExportIsTheFreeTier(): void
    {
        self::assertSame(EntitlementState::CAP_ARCHIVE, $this->job(JobType::Export, ['mode' => 'A'])->requiredCapability());
    }

    public function testManualImportIsTheFreeTier(): void
    {
        self::assertSame(EntitlementState::CAP_ARCHIVE, $this->job(JobType::Import, ['mode' => 'A'])->requiredCapability());
    }

    /**
     * The trap this method exists for: a push carries JobType::Export, so anything keyed on the
     * type alone would price the paid transfer as a free export.
     */
    public function testPushIsThePaidTierDespiteBeingAnExportJob(): void
    {
        $push = $this->job(JobType::Export, ['mode' => 'B', 'createdBy' => 'push']);

        self::assertSame(JobType::Export, $push->type);
        self::assertSame(EntitlementState::CAP_DIRECT, $push->requiredCapability());
    }

    /** The import a receive endpoint enqueues for a landed payload is still Mode B work. */
    public function testReceivedImportIsThePaidTier(): void
    {
        self::assertSame(EntitlementState::CAP_DIRECT, $this->job(JobType::Import, ['mode' => 'B'])->requiredCapability());
    }

    public function testReceiveTypeIsThePaidTier(): void
    {
        self::assertSame(EntitlementState::CAP_DIRECT, $this->job(JobType::Receive, [])->requiredCapability());
    }

    /** Absent metadata must fall to the restrictive-by-default free tier, never to the paid one. */
    public function testJobWithoutModeMetadataIsTheFreeTier(): void
    {
        self::assertSame(EntitlementState::CAP_ARCHIVE, $this->job(JobType::Export, [])->requiredCapability());
    }

    /** Only an exact 'B' means Mode B; a stray value must not be read as the paid tier. */
    public function testOnlyAnExactModeBMarkerMeansThePaidTier(): void
    {
        foreach (['b', 'B2', ' B', 1, true, null] as $mode) {
            self::assertSame(
                EntitlementState::CAP_ARCHIVE,
                $this->job(JobType::Export, ['mode' => $mode])->requiredCapability(),
                sprintf('mode %s must not read as Mode B', var_export($mode, true)),
            );
        }
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function job(JobType $type, array $meta): Job
    {
        $job = new Job('test-job', $type);
        $job->meta = $meta;

        return $job;
    }
}
