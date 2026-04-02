<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Oriceon\N8nBridge\CircuitBreaker\CircuitBreakerManager;
use Oriceon\N8nBridge\DTOs\N8nPayload;
use Oriceon\N8nBridge\Enums\DeliveryStatus;
use Oriceon\N8nBridge\Events\N8nDeliveryDeadEvent;
use Oriceon\N8nBridge\Events\N8nPayloadFailedEvent;
use Oriceon\N8nBridge\Events\N8nPayloadProcessedEvent;
use Oriceon\N8nBridge\Inbound\N8nInboundHandler;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Random\RandomException;

/**
 * Processes one inbound n8n delivery asynchronously.
 *
 * Retry / DLQ logic is handled by N8nDelivery::markFailed().
 * Uses #[Tries] and #[Timeout] Laravel 13 attributes.
 */
#[Tries(3)]
#[Timeout(60)]
final class ProcessN8nInboundJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly int|string $deliveryId,
        private readonly int|string $endpointId,
    ) {
    }

    /**
     * @throws \Throwable
     */
    public function handle(CircuitBreakerManager $circuitBreaker): void
    {
        $delivery = N8nDelivery::findOrFail($this->deliveryId);
        $endpoint = N8nEndpoint::findOrFail($this->endpointId);

        $delivery->update(['status' => DeliveryStatus::Processing]);

        $startedAt = microtime(true);

        try {
            // Circuit breaker check
            $state = $circuitBreaker->getState($delivery->workflow);

            if ( ! $state->allowsRequests()) {
                // Don't consume retry budget — release back to the queue
                $this->release(
                    delay: config('n8n-bridge.circuit_breaker.cooldown_sec', 60)
                );

                return;
            }

            // Resolve and validate handler
            /** @var N8nInboundHandler $handler */
            $handler = app($endpoint->handler_class);
            $payload = N8nPayload::fromRequest(
                $delivery->payload ?? [],
                []
            );

            // Run validation rules if the handler defines them
            $rules = $handler->rules();

            if ( ! empty($rules)) {
                Validator::make($payload->all(), $rules, $handler->messages())->validate();
            }

            // Execute
            $handler->handle($payload);

            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
            $delivery->markProcessed($durationMs);

            $circuitBreaker->recordSuccess($delivery->workflow);

            event(new N8nPayloadProcessedEvent($delivery));

        }
        catch (ValidationException $e) {
            // Validation failures go straight to DLQ — no retry
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
            $delivery->update([
                'status'        => DeliveryStatus::Dlq,
                'error_message' => $e->getMessage(),
                'error_class'   => $e::class,
                'duration_ms'   => $durationMs,
                'attempts'      => $delivery->attempts + 1,
            ]);

            event(new N8nDeliveryDeadEvent($delivery));
            $circuitBreaker->recordFailure($delivery->workflow);

        }
        catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
            $delivery->markFailed($e->getMessage(), $e::class, $durationMs);

            $circuitBreaker->recordFailure($delivery->workflow);

            if ($delivery->fresh()->status === DeliveryStatus::Dlq) {
                event(new N8nDeliveryDeadEvent($delivery->fresh()));
            }
            else {
                event(new N8nPayloadFailedEvent($delivery->fresh(), $e));
            }

            // Re-throw so Laravel queue retries
            throw $e;
        }
    }

    /**
     * Exponential backoff using the endpoint's RetryStrategy.
     *
     *
     * @throws RandomException
     * @return list<int>
     */
    public function backoff(): array
    {
        $endpoint = N8nEndpoint::find($this->endpointId);

        if ($endpoint === null) {
            return [10, 30, 60];
        }

        $strategy = $endpoint->retry_strategy;

        return [
            $strategy->delaySeconds(0),
            $strategy->delaySeconds(1),
            $strategy->delaySeconds(2),
        ];
    }

    public function failed(\Throwable $exception): void
    {
        // This runs after ALL retries exhausted
        $delivery = N8nDelivery::find($this->deliveryId);

        if ($delivery && $delivery->status !== DeliveryStatus::Dlq) {
            $delivery->update(['status' => DeliveryStatus::Dlq]);
            event(new N8nDeliveryDeadEvent($delivery));
        }
    }
}
