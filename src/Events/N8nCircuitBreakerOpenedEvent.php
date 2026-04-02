<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Oriceon\N8nBridge\Models\N8nWorkflow;

/** Fired when a circuit breaker transitions to Open state. */
final class N8nCircuitBreakerOpenedEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param N8nWorkflow $workflow
     * @param int $failureCount
     */
    public function __construct(
        public readonly N8nWorkflow $workflow,
        public readonly int $failureCount,
    ) {
    }
}
