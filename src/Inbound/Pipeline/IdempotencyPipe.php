<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Inbound\Pipeline;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Oriceon\N8nBridge\Enums\DeliveryDirection;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nEndpoint;

final readonly class IdempotencyPipe
{
    public function handle(array $passable, \Closure $next): mixed
    {
        /** @var Request $request */
        /** @var N8nEndpoint $endpoint */
        [$request, $endpoint, $workflow] = $passable;

        $key = $request->header('X-N8N-Execution-Id')
            ?? $request->header('X-Idempotency-Key');

        if ($key === null) {
            return $next($passable);
        }

        // Normalize to string for consistent DB lookups
        $key = (string) $key;

        $existing = N8nDelivery::query()
            ->where('workflow_id', $workflow->id)
            ->where('idempotency_key', $key)
            ->where('direction', DeliveryDirection::Inbound->value)
            ->lockForUpdate()
            ->first();

        if ($existing === null) {
            return $next($passable);
        }

        if ($existing->status->isTerminal()) {
            return new JsonResponse([
                'status'      => 'skipped',
                'reason'      => 'Already processed.',
                'delivery_id' => $existing->id,
            ], 200);
        }

        return new JsonResponse([
            'status'  => 'processing',
            'message' => 'Delivery in progress.',
        ], 202);
    }
}
