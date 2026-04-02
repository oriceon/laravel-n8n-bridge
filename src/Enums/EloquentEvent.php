<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Enums;

enum EloquentEvent: string
{
    case Created  = 'created';
    case Updated  = 'updated';
    case Deleted  = 'deleted';
    case Restored = 'restored';
    case Saved    = 'saved';

    public function label(): string
    {
        return match($this) {
            self::Created  => 'Model Created',
            self::Updated  => 'Model Updated',
            self::Deleted  => 'Model Deleted',
            self::Restored => 'Model Restored',
            self::Saved    => 'Model Saved',
        };
    }
}
