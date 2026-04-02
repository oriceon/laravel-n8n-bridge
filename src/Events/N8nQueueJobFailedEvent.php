<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Oriceon\N8nBridge\Models\N8nQueueFailure;
use Oriceon\N8nBridge\Models\N8nQueueJob;

final class N8nQueueJobFailedEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param N8nQueueJob $job
     * @param N8nQueueFailure $failure
     */
    public function __construct(
        public readonly N8nQueueJob $job,
        public readonly N8nQueueFailure $failure,
    ) {
    }
}
