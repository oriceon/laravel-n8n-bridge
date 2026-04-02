<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Enums;

enum CircuitBreakerState: string
{
    case Closed   = 'closed';
    case Open     = 'open';
    case HalfOpen = 'half_open';

    public function allowsRequests(): bool
    {
        return match($this) {
            self::Closed, self::HalfOpen => true,
            self::Open => false,
        };
    }

    public function label(): string
    {
        return match($this) {
            self::Closed   => 'Closed (healthy)',
            self::Open     => 'Open (blocked)',
            self::HalfOpen => 'Half-Open (probing)',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Closed   => 'green',
            self::Open     => 'red',
            self::HalfOpen => 'yellow',
        };
    }
}
