<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Enums;

/**
 * Priority levels for queued n8n workflow jobs.
 *
 * Higher value = processed first.
 * Workers use ORDER BY priority DESC, available_at ASC.
 */
enum QueueJobPriority: int
{
    case Critical  = 100;  // Billing, security events — process immediately
    case High      = 75;   // User-facing actions — process within seconds
    case Normal    = 50;   // Default — standard processing
    case Low       = 25;   // Background sync, reports
    case Bulk      = 10;   // Mass operations, imports — process when idle

    public function label(): string
    {
        return match($this) {
            self::Critical => 'Critical',
            self::High     => 'High',
            self::Normal   => 'Normal',
            self::Low      => 'Low',
            self::Bulk     => 'Bulk',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Critical => 'red',
            self::High     => 'orange',
            self::Normal   => 'blue',
            self::Low      => 'gray',
            self::Bulk     => 'slate',
        };
    }

    public function defaultMaxAttempts(): int
    {
        return match($this) {
            self::Critical => 10,
            self::High     => 5,
            self::Normal   => 3,
            self::Low      => 3,
            self::Bulk     => 2,
        };
    }

    public function defaultTimeoutSeconds(): int
    {
        return match($this) {
            self::Critical => 30,
            self::High     => 60,
            self::Normal   => 120,
            self::Low      => 300,
            self::Bulk     => 600,
        };
    }
}
