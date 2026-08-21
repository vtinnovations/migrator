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

use Vtinnovations\Migrator\Config\EntitlementState;

/**
 * A migration job: an ordered list of named steps advanced by JobRunner.
 *
 * The runner re-runs the step at $cursor until it returns completeStep(), then
 * advances. Resume state for the current step lives in $payload, so a job survives
 * max_execution_time and resumes via cron or an AJAX poll.
 */
final class Job implements \JsonSerializable
{
    /** @var array<int, string> ordered step names */
    public array $steps = [];

    public int $cursor = 0;

    /** @var array<string, mixed> free-form resume state, keyed however steps like */
    public array $payload = [];

    /** @var array<string, mixed> immutable-ish job metadata (mode, peer, source url, ...) */
    public array $meta = [];

    /** @var array<int, array{ts: float, level: string, msg: string}> */
    public array $log = [];

    public JobState $state = JobState::Pending;

    public ?string $error = null;

    public float $createdAt;

    public float $updatedAt;

    private const LOG_CAP = 500;

    public function __construct(
        public readonly string $id,
        public readonly JobType $type,
    ) {
        $this->createdAt = microtime(true);
        $this->updatedAt = $this->createdAt;
    }

    /**
     * The capability this job's own work requires.
     *
     * Mode B is the direct server-to-server path: an outbound push (an Export job flagged mode B)
     * and the import a receive endpoint enqueues for a landed payload. Everything else is manual
     * package work. The classification is derived from the job's own recorded metadata, so a
     * dashboard poll, a cron tick and the recovery panel all judge the same job identically — and
     * a job cannot change tier after it was created.
     */
    public function requiredCapability(): string
    {
        if (JobType::Receive === $this->type || 'B' === ($this->meta['mode'] ?? null)) {
            return EntitlementState::CAP_DIRECT;
        }

        return EntitlementState::CAP_ARCHIVE;
    }

    public function currentStep(): ?string
    {
        return $this->steps[$this->cursor] ?? null;
    }

    public function advance(): void
    {
        ++$this->cursor;
    }

    public function isDone(): bool
    {
        return $this->cursor >= \count($this->steps);
    }

    public function progress(): float
    {
        $total = \count($this->steps);

        if (0 === $total) {
            return 0.0;
        }

        return min(1.0, $this->cursor / $total);
    }

    /** Language this job's messages render in — captured from the operator at creation. */
    public function lang(): string
    {
        return 'de' === (string) ($this->meta['lang'] ?? '') ? 'de' : 'en';
    }

    public function addLog(string $msg, string $level = 'info'): void
    {
        // Stored raw (English/canonical); translation happens at DISPLAY time (status controller /
        // recovery panel) so it follows the viewer's language and covers jobs logged earlier.
        $this->log[] = ['ts' => microtime(true), 'level' => $level, 'msg' => $msg];

        if (\count($this->log) > self::LOG_CAP) {
            $this->log = \array_slice($this->log, -self::LOG_CAP);
        }
    }

    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type->value,
            'state' => $this->state->value,
            'steps' => $this->steps,
            'cursor' => $this->cursor,
            'payload' => $this->payload,
            'meta' => $this->meta,
            'log' => $this->log,
            'error' => $this->error,
            'createdAt' => $this->createdAt,
            'updatedAt' => $this->updatedAt,
        ];
    }

    public static function fromArray(array $data): self
    {
        $job = new self((string) $data['id'], JobType::from((string) $data['type']));
        $job->state = JobState::from((string) ($data['state'] ?? 'pending'));
        $job->steps = array_values(array_map('strval', $data['steps'] ?? []));
        $job->cursor = (int) ($data['cursor'] ?? 0);
        $job->payload = (array) ($data['payload'] ?? []);
        $job->meta = (array) ($data['meta'] ?? []);
        $job->log = (array) ($data['log'] ?? []);
        $job->error = isset($data['error']) ? (string) $data['error'] : null;
        $job->createdAt = (float) ($data['createdAt'] ?? microtime(true));
        $job->updatedAt = (float) ($data['updatedAt'] ?? $job->createdAt);

        return $job;
    }
}
