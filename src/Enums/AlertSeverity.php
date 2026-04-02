<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Enums;

enum AlertSeverity: string
{
    case Info     = 'info';
    case Warning  = 'warning';
    case Error    = 'error';
    case Critical = 'critical';

    public function emoji(): string
    {
        return match($this) {
            self::Info     => 'ℹ️',
            self::Warning  => '⚠️',
            self::Error    => '❌',
            self::Critical => '🚨',
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Info     => '#3498db',
            self::Warning  => '#f39c12',
            self::Error    => '#e74c3c',
            self::Critical => '#8e44ad',
        };
    }

    public function shouldPage(): bool
    {
        return $this === self::Critical;
    }
}
