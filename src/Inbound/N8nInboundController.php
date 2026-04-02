<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Inbound;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Pipeline\Pipeline;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\DB;
use Oriceon\N8nBridge\Events\N8nPayloadReceivedEvent;
use Oriceon\N8nBridge\Inbound\Pipeline\ApiKeyVerifierPipe;
use Oriceon\N8nBridge\Inbound\Pipeline\HmacVerifierPipe;
use Oriceon\N8nBridge\Inbound\Pipeline\IdempotencyPipe;
use Oriceon\N8nBridge\Inbound\Pipeline\IpWhitelistPipe;
use Oriceon\N8nBridge\Inbound\Pipeline\PayloadStorePipe;
use Oriceon\N8nBridge\Inbound\Pipeline\RateLimitPipe;
use Oriceon\N8nBridge\Inbound\Pipeline\WorkflowVerifierPipe;
use Oriceon\N8nBridge\Jobs\ProcessN8nInboundJob;
use Oriceon\N8nBridge\Models\N8nEndpoint;

/**
 * Single controller for all inbound n8n webhook calls.
 *
 * Route: POST /n8n/in/{slug}
 *
 * Pipeline order:
 *   1. RateLimit               — reject if over-limit
 *   2. ApiKeyVerifier          — validate X-N8N-Key / Bearer / HMAC
 *   3. IpWhitelist             — check source IP if configured
 *   4. HmacVerifier            — payload signature check (optional)
 *   5. WorkflowVerifierPipe    — check workflow header
 *   6. Idempotency             — short-circuit if already processed
 *   7. PayloadStore            — persist delivery record
 *   8. ACK 202 + queue job
 */
final class N8nInboundController extends Controller
{
    public function __construct(private readonly Pipeline $pipeline)
    {
    }

    public function receive(Request $request, string $slug): JsonResponse
    {
        $endpoint = N8nEndpoint::query()
            ->where('slug', $slug)
            ->where('is_active', true)
            ->where(function($q) {
                $q->whereNull('expires_at')
                    ->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($endpoint === null) {
            return response()->json(['error' => 'Endpoint not found.'], 404);
        }

        $passable = [$request, $endpoint];

        // Wrap in transaction so IdempotencyPipe's lockForUpdate() prevents duplicates
        /** @var array|JsonResponse $result */
        $result = DB::transaction(function() use ($passable) {
            return $this->pipeline
                ->send($passable)
                ->through([
                    RateLimitPipe::class,
                    ApiKeyVerifierPipe::class,
                    IpWhitelistPipe::class,
                    HmacVerifierPipe::class,
                    WorkflowVerifierPipe::class,
                    IdempotencyPipe::class,
                    PayloadStorePipe::class,
                ])
                ->then(function(array $passable): array {
                    return $passable;
                });
        });

        // If a pipeline returned a JsonResponse (idempotency short-circuit), pass it through
        if ($result instanceof JsonResponse) {
            return $result;
        }

        [$request, $endpoint, $workflow, $delivery] = $result;

        // Fire event for listeners
        event(new N8nPayloadReceivedEvent($delivery));

        // Dispatch async job — ACK immediately
        $job = new ProcessN8nInboundJob($delivery->id, $endpoint->id);

        dispatch($job)->onQueue($endpoint->queue);

        return new JsonResponse([
            'status'      => 'accepted',
            'delivery_id' => $delivery->id,
        ], 202);
    }
}
