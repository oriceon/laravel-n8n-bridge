<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Outbound;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Oriceon\N8nBridge\Auth\WebhookAuthService;
use Oriceon\N8nBridge\Enums\DeliveryDirection;
use Oriceon\N8nBridge\Enums\DeliveryStatus;
use Oriceon\N8nBridge\Events\N8nWorkflowTriggeredEvent;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nWorkflow;

/**
 * Dispatches outbound triggers from Laravel → n8n.
 *
 * Creates a delivery record, calls the n8n webhook with optional authentication,
 * and updates the delivery status based on the response.
 *
 * Authentication is controlled per-workflow via `auth_type` and
 * `auth_key` (stored AES-256 encrypted). Supported strategies:
 *   - none         — no auth header (open webhook)
 *   - header_token — X-N8N-Workflow-Key: <token>
 *   - bearer       — Authorization: Bearer <token>
 *   - hmac_sha256  — X-N8N-Timestamp + X-N8N-Signature: sha256=<hmac>
 */
final class N8nOutboundDispatcher
{
    public function __construct(
        private readonly WebhookAuthService $auth,
        private readonly OutboundRateLimiter $rateLimiter,
    ) {
    }

    public function trigger(
        N8nWorkflow $workflow,
        array $payload,
        bool $async = true,
    ): N8nDelivery {
        // Validate instance config eagerly so misconfiguration throws before any DB writing
        $this->resolveInstanceConfig($workflow->n8n_instance);

        $delivery = N8nDelivery::create([
            'workflow_id' => $workflow->id,
            'direction'   => DeliveryDirection::Outbound,
            'status'      => DeliveryStatus::Processing,
            'payload'     => $payload,
        ]);

        if ($async) {
            dispatch(function() use ($workflow, $payload, $delivery): void {
                $waitSeconds = $this->rateLimiter->check($workflow);

                if ($waitSeconds !== null) {
                    // Re-dispatch with a delay to respect the rate limit
                    dispatch(function() use ($workflow, $payload, $delivery): void {
                        $this->executeRequest($workflow, $payload, $delivery);
                    })
                        ->onQueue(config('n8n-bridge.outbound.queue', 'default'))
                        ->delay(now()->addSeconds($waitSeconds));

                    return;
                }

                $this->executeRequest($workflow, $payload, $delivery);
            })->onQueue(config('n8n-bridge.outbound.queue', 'default'));

            return $delivery;
        }

        // Sync path: fail immediately when rate limited
        $waitSeconds = $this->rateLimiter->check($workflow);

        if ($waitSeconds !== null) {
            $delivery->markFailed("Rate limited — retry in {$waitSeconds}s", self::class, 0);

            return $delivery->fresh();
        }

        $this->executeRequest($workflow, $payload, $delivery);

        return $delivery->fresh();
    }

    private function executeRequest(
        N8nWorkflow $workflow,
        array $payload,
        N8nDelivery $delivery,
    ): void {
        $startedAt = microtime(true);

        try {
            // Validate instance config eagerly (throws on misconfiguration)
            $this->resolveInstanceConfig($workflow->n8n_instance);
            $webhookUrl = $workflow->resolveWebhookUrl();

            $jsonBody = (string) json_encode($payload, JSON_THROW_ON_ERROR);

            $headers = array_merge(
                [
                    'Content-Type' => 'application/json',
                    'Accept'       => 'application/json',
                ],
                $this->auth->buildHeaders($workflow, $jsonBody),
            );

            /** @var Response $response */
            $response = Http::withHeaders($headers)
                ->timeout(config('n8n-bridge.outbound.timeout', 30))
                ->post($webhookUrl, $payload);

            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            if ($response->successful()) {
                $responseData = $response->json() ?? [];

                $delivery->update([
                    'status'           => DeliveryStatus::Done,
                    'response'         => $responseData,
                    'http_status'      => $response->status(),
                    'duration_ms'      => $durationMs,
                    'n8n_execution_id' => $responseData['executionId'] ?? null,
                    'processed_at'     => now(),
                ]);
            }
            else {
                $delivery->update([
                    'status'        => DeliveryStatus::Failed,
                    'response'      => $response->json(),
                    'http_status'   => $response->status(),
                    'duration_ms'   => $durationMs,
                    'error_message' => mb_substr("HTTP {$response->status()}: {$response->body()}", 0, 2000),
                    'attempts'      => $delivery->attempts + 1,
                ]);
            }

            event(new N8nWorkflowTriggeredEvent($workflow, $payload, $delivery->fresh()));

        }
        catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            $delivery->markFailed($e->getMessage(), $e::class, $durationMs);

            // Re-throw configuration errors so callers can detect misconfiguration
            if ($e instanceof \RuntimeException) {
                throw $e;
            }
        }
    }

    /**
     * @return array{url: string, api_key: string}
     */
    private function resolveInstanceConfig(string $instance): array
    {
        $instances = config('n8n-bridge.instances', []);

        return $instances[$instance]
            ?? throw new \RuntimeException("n8n instance [{$instance}] not configured.");
    }
}
