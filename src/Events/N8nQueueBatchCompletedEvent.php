<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Oriceon\N8nBridge\Models\N8nQueueBatch;

final class N8nQueueBatchCompletedEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param N8nQueueBatch $batch
     */
    public function __construct(public readonly N8nQueueBatch $batch)
    {
    }
}
