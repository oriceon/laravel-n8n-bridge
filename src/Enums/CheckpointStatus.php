<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Enums;

/**
 * Status of a single n8n node checkpoint.
 */
enum CheckpointStatus: string
{
    case Running   = 'running';    // Node started, not done yet
    case Completed = 'completed';  // Node finished successfully
    case Failed    = 'failed';     // Node encountered an error
    case Skipped   = 'skipped';    // Node was skipped (conditional branch)
    case Waiting   = 'waiting';    // Node is waiting (e.g., Wait node, webhook)

    public function isTerminal(): bool
    {
        return match($this) {
            self::Completed, self::Failed, self::Skipped => true,
            default => false,
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Running   => 'yellow',
            self::Completed => 'green',
            self::Failed    => 'red',
            self::Skipped   => 'gray',
            self::Waiting   => 'blue',
        };
    }

    public function icon(): string
    {
        return match($this) {
            self::Running   => 'loader',
            self::Completed => 'check-circle',
            self::Failed    => 'x-circle',
            self::Skipped   => 'skip-forward',
            self::Waiting   => 'clock',
        };
    }

    public function label(): string
    {
        return match($this) {
            self::Running   => 'Running',
            self::Completed => 'Completed',
            self::Failed    => 'Failed',
            self::Skipped   => 'Skipped',
            self::Waiting   => 'Waiting',
        };
    }
}
