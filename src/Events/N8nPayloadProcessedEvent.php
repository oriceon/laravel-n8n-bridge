<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Oriceon\N8nBridge\Models\N8nDelivery;

/** Fired after successful handler execution. */
final class N8nPayloadProcessedEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param N8nDelivery $delivery
     */
    public function __construct(public readonly N8nDelivery $delivery)
    {
    }
}
