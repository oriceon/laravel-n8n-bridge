<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Oriceon\N8nBridge\Commands\DlqListCommand;
use Oriceon\N8nBridge\Commands\DlqRetryCommand;
use Oriceon\N8nBridge\Database\Factories\N8nDeliveryFactory;
use Oriceon\N8nBridge\Database\Factories\N8nEndpointFactory;
use Oriceon\N8nBridge\Enums\DeliveryStatus;
use Oriceon\N8nBridge\Models\N8nDelivery;

covers(DlqListCommand::class, DlqRetryCommand::class);

// ── n8n:dlq:list ──────────────────────────────────────────────────────────────

describe('n8n:dlq:list', function() {
    it('reports no entries when DLQ is empty', function() {
        $this->artisan('n8n:dlq:list')
            ->expectsOutputToContain('No DLQ entries')
            ->assertSuccessful();
    });

    it('lists DLQ entries in a table', function() {
        N8nDeliveryFactory::new()->dlq()->create();

        $this->artisan('n8n:dlq:list')
            ->expectsOutputToContain('DLQ entries')
            ->assertSuccessful();
    });

    it('filters by endpoint slug', function() {
        $endpoint = N8nEndpointFactory::new()->create(['slug' => 'invoice-paid']);
        $other    = N8nEndpointFactory::new()->create(['slug' => 'order-shipped']);

        N8nDeliveryFactory::new()->dlq()->create(['endpoint_id' => $endpoint->id]);
        N8nDeliveryFactory::new()->dlq()->create(['endpoint_id' => $other->id]);

        $this->artisan('n8n:dlq:list', ['--endpoint' => 'invoice-paid'])
            ->expectsOutputToContain('DLQ entries')
            ->assertSuccessful();

        expect(
            N8nDelivery::where('status', DeliveryStatus::Dlq->value)
                ->whereHas('endpoint', fn($q) => $q->where('slug', 'invoice-paid'))
                ->count()
        )->toBe(1);
    });

    it('respects --limit option', function() {
        N8nDeliveryFactory::new()->dlq()->count(5)->create();

        $this->artisan('n8n:dlq:list', ['--limit' => '2'])
            ->assertSuccessful();
    });
});

// ── n8n:dlq:retry ─────────────────────────────────────────────────────────────

describe('n8n:dlq:retry', function() {
    it('reports nothing to retry when DLQ is empty', function() {
        Queue::fake();

        $this->artisan('n8n:dlq:retry')
            ->expectsOutputToContain('No DLQ entries to retry')
            ->assertSuccessful();
    });

    it('re-queues all DLQ deliveries that have an endpoint', function() {
        Queue::fake();

        $endpoint = N8nEndpointFactory::new()->create();
        $d1       = N8nDeliveryFactory::new()->dlq()->create(['endpoint_id' => $endpoint->id]);
        $d2       = N8nDeliveryFactory::new()->dlq()->create(['endpoint_id' => $endpoint->id]);

        $this->artisan('n8n:dlq:retry')
            ->expectsOutputToContain('Re-queued 2 deliveries')
            ->assertSuccessful();

        expect($d1->fresh()->status)->toBe(DeliveryStatus::Received)
            ->and($d1->fresh()->attempts)->toBe(0)
            ->and($d2->fresh()->status)->toBe(DeliveryStatus::Received);
    });

    it('re-queues a specific delivery by uuid', function() {
        Queue::fake();

        $endpoint = N8nEndpointFactory::new()->create();
        $target   = N8nDeliveryFactory::new()->dlq()->create(['endpoint_id' => $endpoint->id]);
        $other    = N8nDeliveryFactory::new()->dlq()->create(['endpoint_id' => $endpoint->id]);

        $this->artisan('n8n:dlq:retry', ['id' => $target->uuid])
            ->expectsOutputToContain('Re-queued 1 deliveries')
            ->assertSuccessful();

        expect($target->fresh()->status)->toBe(DeliveryStatus::Received)
            ->and($other->fresh()->status)->toBe(DeliveryStatus::Dlq);
    });

    it('reports nothing when specific uuid is not in DLQ', function() {
        Queue::fake();

        $done = N8nDeliveryFactory::new()->done()->create();

        $this->artisan('n8n:dlq:retry', ['id' => $done->uuid])
            ->expectsOutputToContain('No DLQ entries to retry')
            ->assertSuccessful();
    });
});
