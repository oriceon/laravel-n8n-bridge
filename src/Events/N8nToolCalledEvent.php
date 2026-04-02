<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Oriceon\N8nBridge\DTOs\N8nToolResponse;
use Oriceon\N8nBridge\Models\N8nTool;

/** Fired when a tool endpoint is called from n8n. */
final class N8nToolCalledEvent
{
    use Dispatchable;
    use SerializesModels;

    /**
     * @param N8nTool $tool
     * @param array $input
     * @param N8nToolResponse $response
     * @param int $durationMs
     */
    public function __construct(
        public readonly N8nTool $tool,
        public readonly array $input,
        public readonly N8nToolResponse $response,
        public readonly int $durationMs,
    ) {
    }
}
