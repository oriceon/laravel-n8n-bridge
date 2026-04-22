<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Oriceon\N8nBridge\Enums\DeliveryStatus;
use Oriceon\N8nBridge\Events\N8nPayloadReceivedEvent;
use Oriceon\N8nBridge\Inbound\N8nInboundController;
use Oriceon\N8nBridge\Models\N8nApiKey;
use Oriceon\N8nBridge\Models\N8nCredential;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Oriceon\N8nBridge\Models\N8nWorkflow;
use Tests\Support\NoOpHandler;

covers(N8nInboundController::class);

// ── Helper: register a simple handler class in-memory ────────────────────────

beforeEach(function () {
    // Register a no-op handler that tests can override
    if (! class_exists(NoOpHandler::class)) {
        eval('
            namespace Tests\Support;
            use Oriceon\N8nBridge\DTOs\N8nPayload;
            use Oriceon\N8nBridge\Inbound\N8nInboundHandler;
            final class NoOpHandler extends N8nInboundHandler {
                public function handle(N8nPayload $payload): void {}
            }
        ');
    }

    $this->credential = N8nCredential::create(['name' => 'Test Credential', 'is_active' => true]);
    $this->workflow = N8nWorkflow::factory()->create();
    $this->endpoint = N8nEndpoint::factory()
        ->forCredential($this->credential)
        ->create([
            'slug' => 'test-endpoint',
            'handler_class' => 'Tests\Support\NoOpHandler',
        ]);
    [$this->apiKey] = N8nApiKey::generate($this->credential->id);
});

describe('POST /n8n/in/{slug}', function () {

    it('returns 202 with valid API key', function () {
        $response = $this->postJson('/n8n/in/test-endpoint', ['invoice_id' => 42], [
            'X-N8N-Key' => $this->apiKey,
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
            'X-N8N-Execution-Id' => random_int(1000, 2000),
        ]);

        $response->assertStatus(202)
            ->assertJsonStructure(['status', 'delivery_id']);

        expect($response->json('status'))->toBe('accepted');
    });

    it('creates a delivery record', function () {
        $executionId = random_int(1000, 2000);

        $this->postJson('/n8n/in/test-endpoint', ['invoice_id' => 42], [
            'X-N8N-Key' => $this->apiKey,
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
            'X-N8N-Execution-Id' => $executionId,
        ]);

        expect(N8nDelivery::where('idempotency_key', $executionId)->count())->toBe(1);
    });

    it('returns 401 with missing API key', function () {
        $this->postJson('/n8n/in/test-endpoint', ['data' => 'x'], [
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
            'X-N8N-Execution-Id' => random_int(1000, 2000),
        ])
            ->assertStatus(401);
    });

    it('returns 401 with wrong API key', function () {
        $this->postJson('/n8n/in/test-endpoint', ['data' => 'x'], [
            'X-N8N-Key' => 'n8br_sk_wrongkeyXXXXXXXXXXXXXXXXX',
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
            'X-N8N-Execution-Id' => random_int(1000, 2000),
        ])->assertStatus(401);
    });

    it('returns 404 for unknown slug', function () {
        $this->postJson('/n8n/in/unknown-endpoint', [], [
            'X-N8N-Key' => $this->apiKey,
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
            'X-N8N-Execution-Id' => random_int(1000, 2000),
        ])->assertStatus(404);
    });

    it('fires N8nPayloadReceivedEvent event', function () {
        Event::fake([N8nPayloadReceivedEvent::class]);

        $this->postJson('/n8n/in/test-endpoint', ['data' => 'test'], [
            'X-N8N-Key' => $this->apiKey,
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
            'X-N8N-Execution-Id' => random_int(1000, 2000),
        ]);

        Event::assertDispatched(N8nPayloadReceivedEvent::class);
    });

    it('returns 200 for duplicate idempotency key (already done)', function () {
        $executionId = random_int(1000, 2000);

        // First request — should be accepted
        $this->postJson('/n8n/in/test-endpoint', ['data' => 'first'], [
            'X-N8N-Key' => $this->apiKey,
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
            'X-N8N-Execution-Id' => $executionId,
        ])->assertStatus(202);

        // Mark delivery as done
        N8nDelivery::where('idempotency_key', $executionId)
            ->update(['status' => DeliveryStatus::Done->value]);

        // The second request with the same execution ID — should be skipped
        $this->postJson('/n8n/in/test-endpoint', ['data' => 'second'], [
            'X-N8N-Key' => $this->apiKey,
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
            'X-N8N-Execution-Id' => $executionId,
        ])->assertStatus(200)
            ->assertJsonPath('status', 'skipped');
    });

    it('returns 429 when rate limit exceeded', function () {
        // Set a very low rate limit
        $this->endpoint->update(['rate_limit' => 1]);

        // First request OK
        $this->postJson('/n8n/in/test-endpoint', [], [
            'X-N8N-Key' => $this->apiKey,
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
            'X-N8N-Execution-Id' => random_int(1000, 2000),
        ])->assertStatus(202);

        // Second request exceeds limit
        $this->postJson('/n8n/in/test-endpoint', [], [
            'X-N8N-Key' => $this->apiKey,
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
            'X-N8N-Execution-Id' => random_int(1000, 2000),
        ])->assertStatus(429);
    });

    it('returns 403 when client IP is not whitelisted', function () {
        $this->endpoint->update(['allowed_ips' => ['10.0.0.1', '10.0.0.2']]);

        $this->postJson('/n8n/in/test-endpoint', [], [
            'X-N8N-Key' => $this->apiKey,
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
            'X-N8N-Execution-Id' => random_int(1000, 2000),
        ])->assertStatus(403);
    });

    it('returns 403 when missing X-N8N-Workflow-Id header', function () {
        $this->postJson('/n8n/in/test-endpoint', [], [
            'X-N8N-Key' => $this->apiKey,
            'X-N8N-Execution-Id' => random_int(1000, 2000),
        ])->assertStatus(403);
    });

    it('returns 403 when missing X-N8N-Execution-Id header', function () {
        $this->postJson('/n8n/in/test-endpoint', [], [
            'X-N8N-Key' => $this->apiKey,
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
        ])->assertStatus(403);
    });

    it('stores payload when store_payload is true', function () {
        $executionId = random_int(1000, 2000);
        $this->endpoint->update(['store_payload' => true]);

        $this->postJson('/n8n/in/test-endpoint', ['invoice_id' => 99], [
            'X-N8N-Key' => $this->apiKey,
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
            'X-N8N-Execution-Id' => $executionId,
        ]);

        $delivery = N8nDelivery::where('idempotency_key', $executionId)->first();
        expect($delivery->payload)->toBe(['invoice_id' => 99]);
    });

    it('does not store payload when store_payload is false', function () {
        $executionId = random_int(1000, 2000);
        $this->endpoint->update(['store_payload' => false]);

        $this->postJson('/n8n/in/test-endpoint', ['secret' => 'data'], [
            'X-N8N-Key' => $this->apiKey,
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
            'X-N8N-Execution-Id' => $executionId,
        ]);

        $delivery = N8nDelivery::where('idempotency_key', $executionId)->first();
        expect($delivery->payload)->toBeNull();
    });

    it('rejects expired endpoint', function () {
        $this->endpoint->update(['expires_at' => now()->subHour()]);

        $this->postJson('/n8n/in/test-endpoint', [], [
            'X-N8N-Key' => $this->apiKey,
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
            'X-N8N-Execution-Id' => random_int(1000, 2000),
        ])->assertStatus(404);
    });

    it('rejects inactive endpoint', function () {
        $this->endpoint->update(['is_active' => false]);

        $this->postJson('/n8n/in/test-endpoint', [], [
            'X-N8N-Key' => $this->apiKey,
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
            'X-N8N-Execution-Id' => random_int(1000, 2000),
        ])->assertStatus(404);
    });

    it('accepts slash-separated slug (e.g. /n8n/in/invoices/paid)', function () {
        N8nEndpoint::factory()
            ->forCredential($this->credential)
            ->create([
                'slug' => 'invoices/paid',
                'handler_class' => 'Tests\Support\NoOpHandler',
            ]);

        $this->postJson('/n8n/in/invoices/paid', ['invoice_id' => 1], [
            'X-N8N-Key' => $this->apiKey,
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
            'X-N8N-Execution-Id' => random_int(1000, 2000),
        ])->assertStatus(202);
    });

    it('slug with hyphens and slug with slashes can coexist', function () {
        N8nEndpoint::factory()
            ->forCredential($this->credential)
            ->create([
                'slug' => 'orders/shipped',
                'handler_class' => 'Tests\Support\NoOpHandler',
            ]);

        $this->postJson('/n8n/in/test-endpoint', [], [
            'X-N8N-Key' => $this->apiKey,
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
            'X-N8N-Execution-Id' => random_int(1000, 2000),
        ])->assertStatus(202);

        $this->postJson('/n8n/in/orders/shipped', [], [
            'X-N8N-Key' => $this->apiKey,
            'X-N8N-Workflow-Id' => $this->workflow->n8n_id,
            'X-N8N-Execution-Id' => random_int(1000, 2000),
        ])->assertStatus(202);
    });
});
