<?php

declare(strict_types=1);

namespace Vtinnovations\Migrator\Job;

enum JobState: string
{
    case Pending = 'pending';
    case Running = 'running';
    case Paused = 'paused';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isTerminal(): bool
    {
        return $this === self::Completed || $this === self::Failed || $this === self::Cancelled;
    }
}
