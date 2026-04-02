<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Enums;

enum HttpMethod: string
{
    case Get    = 'GET';
    case Post   = 'POST';
    case Put    = 'PUT';
    case Patch  = 'PATCH';
    case Delete = 'DELETE';

    public function hasBody(): bool
    {
        return match($this) {
            self::Post, self::Put, self::Patch => true,
            self::Get, self::Delete => false,
        };
    }
}
