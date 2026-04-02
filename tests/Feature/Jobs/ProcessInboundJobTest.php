<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Oriceon\N8nBridge\Enums\DeliveryStatus;
use Oriceon\N8nBridge\Events\N8nDeliveryDeadEvent;
use Oriceon\N8nBridge\Events\N8nPayloadFailedEvent;
use Oriceon\N8nBridge\Events\N8nPayloadProcessedEvent;
use Oriceon\N8nBridge\Jobs\ProcessN8nInboundJob;
use Oriceon\N8nBridge\Models\N8nCredential;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Oriceon\N8nBridge\Models\N8nWorkflow;

covers(ProcessN8nInboundJob::class);

// Inline handler definitions for testing
beforeAll(function() {
    if ( ! class_exists('Tests\Jobs\SuccessHandler')) {
        eval('
            namespace Tests\Jobs;
            use Oriceon\N8nBridge\DTOs\N8nPayload;
            use Oriceon\N8nBridge\Inbound\N8nInboundHandler;
            final class SuccessHandler extends N8nInboundHandler {
                public function handle(N8nPayload $payload): void {}
            }
        ');
    }

    if ( ! class_exists('Tests\Jobs\ThrowingHandler')) {
        eval('
            namespace Tests\Jobs;
            use Oriceon\N8nBridge\DTOs\N8nPayload;
            use Oriceon\N8nBridge\Inbound\N8nInboundHandler;
            final class ThrowingHandler extends N8nInboundHandler {
                public function handle(N8nPayload $payload): void {
                    throw new \RuntimeException("Handler exploded");
                }
            }
        ');
    }

    if ( ! class_exists('Tests\Jobs\ValidationHandler')) {
        eval('
            namespace Tests\Jobs;
            use Oriceon\N8nBridge\DTOs\N8nPayload;
            use Oriceon\N8nBridge\Inbound\N8nInboundHandler;
            final class ValidationHandler extends N8nInboundHandler {
                public function rules(): array { return ["invoice_id" => "required|integer"]; }
                public function handle(N8nPayload $payload): void {}
            }
        ');
    }
});

beforeEach(function() {
    $this->workflow   = N8nWorkflow::factory()->create();
    $this->credential = N8nCredential::create(['name' => 'Test', 'is_active' => true]);

    $this->endpoint = N8nEndpoint::factory()->create([
        'handler_class' => 'Tests\Jobs\SuccessHandler',
        'credential_id' => $this->credential->id,
        'max_attempts'  => 3,
    ]);

    $this->delivery = N8nDelivery::factory()->create([
        'workflow_id' => $this->workflow->id,
        'endpoint_id' => $this->endpoint->id,
        'status'      => DeliveryStatus::Received->value,
        'payload'     => ['invoice_id' => 42],
    ]);
});

// ── Successful handle ─────────────────────────────────────────────────────────

describe('ProcessN8nInboundJob successful handling', function() {

    it('marks delivery as Done on success', function() {
        Event::fake([N8nPayloadProcessedEvent::class]);

        dispatch_sync(new ProcessN8nInboundJob($this->delivery->id, $this->endpoint->id));

        expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Done);
        Event::assertDispatched(N8nPayloadProcessedEvent::class);
    });

    it('records duration_ms after processing', function() {
        dispatch_sync(new ProcessN8nInboundJob($this->delivery->id, $this->endpoint->id));

        expect($this->delivery->fresh()->duration_ms)->toBeInt()->toBeGreaterThanOrEqual(0);
    });
});

// ── Handler exception ─────────────────────────────────────────────────────────

describe('ProcessN8nInboundJob exception handling', function() {

    it('marks delivery as Failed when handler throws', function() {
        Event::fake([N8nPayloadFailedEvent::class, N8nDeliveryDeadEvent::class]);

        $this->endpoint->update(['handler_class' => 'Tests\Jobs\ThrowingHandler']);

        try {
            dispatch_sync(new ProcessN8nInboundJob($this->delivery->id, $this->endpoint->id));
        }
        catch (Throwable) {
        }

        $status = $this->delivery->fresh()->status;
        expect($status)->toBeIn([DeliveryStatus::Failed, DeliveryStatus::Dlq]);
    });
});

// ── Validation failure ────────────────────────────────────────────────────────

describe('ProcessN8nInboundJob validation failure', function() {

    it('sends delivery straight to DLQ on validation failure', function() {
        Event::fake([N8nDeliveryDeadEvent::class]);

        $this->endpoint->update(['handler_class' => 'Tests\Jobs\ValidationHandler']);
        // Missing invoice_id
        $this->delivery->update(['payload' => ['wrong_field' => 'bad']]);

        dispatch_sync(new ProcessN8nInboundJob($this->delivery->id, $this->endpoint->id));

        expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Dlq);
        Event::assertDispatched(N8nDeliveryDeadEvent::class);
    });
});

// ── backoff() ─────────────────────────────────────────────────────────────────

describe('ProcessN8nInboundJob::backoff()', function() {

    it('returns an array of 3 delay values', function() {
        $job     = new ProcessN8nInboundJob($this->delivery->id, $this->endpoint->id);
        $backoff = $job->backoff();

        expect($backoff)->toHaveCount(3)
            ->and($backoff[0])->toBeInt()
            ->and($backoff[1])->toBeInt()
            ->and($backoff[2])->toBeInt();
    });

    it('returns fallback delays when endpoint not found', function() {
        $job     = new ProcessN8nInboundJob($this->delivery->id, 99999);
        $backoff = $job->backoff();

        expect($backoff)->toBe([10, 30, 60]);
    });
});

// ── failed() callback ─────────────────────────────────────────────────────────

describe('ProcessN8nInboundJob::failed()', function() {
    it('moves delivery to DLQ if not already there', function() {
        Event::fake([N8nDeliveryDeadEvent::class]);

        $job = new ProcessN8nInboundJob($this->delivery->id, $this->endpoint->id);
        $job->failed(new RuntimeException('Final failure'));

        expect($this->delivery->fresh()->status)->toBe(DeliveryStatus::Dlq);
        Event::assertDispatched(N8nDeliveryDeadEvent::class);
    });

    it('does not re-dispatch event if already in DLQ', function() {
        Event::fake([N8nDeliveryDeadEvent::class]);

        $this->delivery->update(['status' => DeliveryStatus::Dlq->value]);

        $job = new ProcessN8nInboundJob($this->delivery->id, $this->endpoint->id);
        $job->failed(new RuntimeException('Already dead'));

        Event::assertNotDispatched(N8nDeliveryDeadEvent::class);
    });
});
