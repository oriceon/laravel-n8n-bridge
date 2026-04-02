<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Oriceon\N8nBridge\Models\N8nDelivery;

/** Fired when a delivery fails (but still has retries left). */
final class N8nPayloadFailedEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param N8nDelivery $delivery
     * @param \Throwable $exception
     */
    public function __construct(
        public readonly N8nDelivery $delivery,
        public readonly \Throwable $exception,
    ) {
    }
}
