<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Enums;

enum StatPeriod: string
{
    case Hourly  = 'hourly';
    case Daily   = 'daily';
    case Weekly  = 'weekly';
    case Monthly = 'monthly';

    public function carbonMethod(): string
    {
        return match($this) {
            self::Hourly  => 'startOfHour',
            self::Daily   => 'startOfDay',
            self::Weekly  => 'startOfWeek',
            self::Monthly => 'startOfMonth',
        };
    }

    public function groupByFormat(): string
    {
        return match($this) {
            self::Hourly  => 'Y-m-d H:00',
            self::Daily   => 'Y-m-d',
            self::Weekly  => 'Y-W',
            self::Monthly => 'Y-m',
        };
    }

    public function sqlDateFormat(): string
    {
        return match($this) {
            self::Hourly  => '%Y-%m-%d %H:00',
            self::Daily   => '%Y-%m-%d',
            self::Weekly  => '%Y-%u',
            self::Monthly => '%Y-%m',
        };
    }
}
