<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nWorkflow;

/** Fired when an outbound workflow trigger is dispatched. */
final class N8nWorkflowTriggeredEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param N8nWorkflow $workflow
     * @param array $payload
     * @param N8nDelivery|null $delivery
     */
    public function __construct(
        public readonly N8nWorkflow $workflow,
        public readonly array $payload,
        public readonly ?N8nDelivery $delivery,
    ) {
    }
}
