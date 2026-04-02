<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Enums;

enum DeliveryStatus: string
{
    case Received   = 'received';
    case Processing = 'processing';
    case Done       = 'done';
    case Failed     = 'failed';
    case Retrying   = 'retrying';
    case Dlq        = 'dlq';
    case Skipped    = 'skipped';

    public function isTerminal(): bool
    {
        return match($this) {
            self::Done, self::Dlq, self::Skipped => true,
            default => false,
        };
    }

    public function isRetryable(): bool
    {
        return match($this) {
            self::Failed, self::Retrying => true,
            default => false,
        };
    }

    public function isSuccess(): bool
    {
        return $this === self::Done;
    }

    public function isFailure(): bool
    {
        return match($this) {
            self::Failed, self::Dlq => true,
            default => false,
        };
    }

    public function color(): string
    {
        return match($this) {
            self::Done       => 'green',
            self::Failed     => 'red',
            self::Dlq        => 'orange',
            self::Retrying   => 'yellow',
            self::Skipped    => 'gray',
            self::Processing => 'blue',
            self::Received   => 'indigo',
        };
    }

    public function label(): string
    {
        return match($this) {
            self::Received   => 'Received',
            self::Processing => 'Processing',
            self::Done       => 'Done',
            self::Failed     => 'Failed',
            self::Retrying   => 'Retrying',
            self::Dlq        => 'Dead Letter Queue',
            self::Skipped    => 'Skipped (duplicate)',
        };
    }

    /** @return list<self> */
    public static function terminal(): array
    {
        return [self::Done, self::Dlq, self::Skipped];
    }

    /** @return list<self> */
    public static function active(): array
    {
        return [self::Received, self::Processing, self::Retrying];
    }
}
