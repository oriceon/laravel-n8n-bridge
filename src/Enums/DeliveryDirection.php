<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Enums;

enum DeliveryDirection: string
{
    case Inbound  = 'inbound';
    case Outbound = 'outbound';
    case Tool     = 'tool';
    case Queue    = 'queue';
}
