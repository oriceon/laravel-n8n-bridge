<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Enums;

enum ApiKeyStatus: string
{
    case Active  = 'active';
    case Grace   = 'grace';
    case Revoked = 'revoked';

    public function isUsable(): bool
    {
        return match($this) {
            self::Active, self::Grace => true,
            self::Revoked => false,
        };
    }

    public function label(): string
    {
        return match($this) {
            self::Active  => 'Active',
            self::Grace   => 'Grace Period',
            self::Revoked => 'Revoked',
        };
    }
}
