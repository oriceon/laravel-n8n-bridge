<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Inbound\Pipeline;

use Illuminate\Http\Request;
use Oriceon\N8nBridge\Enums\DeliveryDirection;
use Oriceon\N8nBridge\Enums\DeliveryStatus;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class PayloadStorePipe
{
    public function handle(array $passable, \Closure $next): mixed
    {
        /** @var Request $request */
        /** @var N8nEndpoint $endpoint */
        [$request, $endpoint, $workflow] = $passable;

        $executionId = $request->header('X-N8N-Execution-Id');

        if (empty($executionId)) {
            throw new AccessDeniedHttpException('Missing X-N8N-Execution-Id header.');
        }

        // Validate format: must be a reasonable length string (numeric IDs or UUIDs)
        if (strlen($executionId) > 64) {
            throw new AccessDeniedHttpException('Invalid X-N8N-Execution-Id format.');
        }

        $delivery = N8nDelivery::create([
            'workflow_id'      => $workflow->id,
            'endpoint_id'      => $endpoint->id,
            'direction'        => DeliveryDirection::Inbound,
            'status'           => DeliveryStatus::Received,
            'idempotency_key'  => $executionId,
            'n8n_execution_id' => $executionId,
            'payload'          => $endpoint->store_payload ? $request->all() : null,
        ]);

        // Append delivery to passable so the job can update it
        $passable[] = $delivery;

        return $next($passable);
    }
}
