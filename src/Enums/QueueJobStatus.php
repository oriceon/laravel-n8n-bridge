<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Enums;

/**
 * Status lifecycle of a queued n8n job.
 *
 * State machine:
 *   pending → claimed → running → done
 *                    ↘ failed → (retry) → pending
 *                             → (exhausted) → dead
 *   pending → canceled
 *   pending → (scheduled delay) → pending (available_at in future)
 */
enum QueueJobStatus: string
{
    case Pending    = 'pending';    // waiting to be claimed
    case Claimed    = 'claimed';    // locked by a worker
    case Running    = 'running';    // HTTP call in progress
    case Done       = 'done';       // completed successfully
    case Failed     = 'failed';     // failed, will retry
    case Dead       = 'dead';       // exhausted all retries → failures table
    case Cancelled  = 'cancelled';  // manually cancelled before processing

    public function isTerminal(): bool
    {
        return match($this) {
            self::Done, self::Dead, self::Cancelled => true,
            default => false,
        };
    }

    public function isActive(): bool
    {
        return match($this) {
            self::Claimed, self::Running => true,
            default => false,
        };
    }

    public function canRetry(): bool
    {
        return $this === self::Failed;
    }

    public function label(): string
    {
        return match($this) {
            self::Pending   => 'Pending',
            self::Claimed   => 'Claimed',
            self::Running   => 'Running',
            self::Done      => 'Done',
            self::Failed    => 'Failed',
            self::Dead      => 'Dead (DLQ)',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Pending   => 'blue',
            self::Claimed   => 'indigo',
            self::Running   => 'yellow',
            self::Done      => 'green',
            self::Failed    => 'orange',
            self::Dead      => 'red',
            self::Cancelled => 'gray',
        };
    }
}
