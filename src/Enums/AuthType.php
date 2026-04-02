<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Enums;

enum AuthType: string
{
    case ApiKey = 'api_key';
    case Bearer = 'bearer';
    case Hmac   = 'hmac';
    case None   = 'none';

    public function headerName(): string
    {
        return match($this) {
            self::ApiKey => 'X-N8N-Key',
            self::Bearer => 'Authorization',
            self::Hmac   => 'X-N8N-Signature',
            self::None   => '',
        };
    }

    public function requiresSecret(): bool
    {
        return match($this) {
            self::ApiKey, self::Bearer, self::Hmac => true,
            self::None => false,
        };
    }
}
