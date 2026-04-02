<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\DeliveryDirection;
use Oriceon\N8nBridge\Enums\DeliveryStatus;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Oriceon\N8nBridge\Models\N8nWorkflow;

covers(N8nDelivery::class);

beforeEach(function() {
    $this->workflow = N8nWorkflow::factory()->create();
    $this->endpoint = N8nEndpoint::factory()->create([
        'max_attempts' => 3,
    ]);
});

describe('N8nDelivery status transitions', function() {
    it('marks delivery as Done with duration', function() {
        $delivery = N8nDelivery::factory()->create([
            'workflow_id' => $this->workflow->id,
            'endpoint_id' => $this->endpoint->id,
            'status'      => DeliveryStatus::Processing,
        ]);

        $delivery->markProcessed(142);

        expect($delivery->fresh())
            ->status->toBe(DeliveryStatus::Done)
            ->duration_ms->toBe(142)
            ->processed_at->not->toBeNull();
    });

    it('marks delivery as Failed within retry budget', function() {
        $delivery = N8nDelivery::factory()->create([
            'workflow_id' => $this->workflow->id,
            'endpoint_id' => $this->endpoint->id,
            'status'      => DeliveryStatus::Processing,
            'attempts'    => 1,
        ]);

        $delivery->markFailed('Connection timeout', RuntimeException::class, 5000);

        expect($delivery->fresh())
            ->status->toBe(DeliveryStatus::Failed)
            ->error_message->toBe('Connection timeout')
            ->error_class->toBe(RuntimeException::class)
            ->attempts->toBe(2);
    });

    it('moves to DLQ when max attempts exhausted', function() {
        $delivery = N8nDelivery::factory()->create([
            'workflow_id' => $this->workflow->id,
            'endpoint_id' => $this->endpoint->id,
            'status'      => DeliveryStatus::Processing,
            'attempts'    => 3,
        ]);

        $delivery->markFailed('Final failure', RuntimeException::class, 1000);

        expect($delivery->fresh()->status)->toBe(DeliveryStatus::Dlq);
    });

    it('marks delivery as Skipped', function() {
        $delivery = N8nDelivery::factory()->create([
            'workflow_id' => $this->workflow->id,
            'status'      => DeliveryStatus::Received,
        ]);

        $delivery->markSkipped();

        expect($delivery->fresh()->status)->toBe(DeliveryStatus::Skipped);
    });
});

describe('N8nDelivery::isTerminal()', function() {
    it('returns true for terminal statuses', function() {
        $done = N8nDelivery::factory()->create([
            'workflow_id' => $this->workflow->id,
            'status'      => DeliveryStatus::Done,
        ]);
        expect($done->isTerminal())->toBeTrue();
    });

    it('returns false for non-terminal statuses', function() {
        $failed = N8nDelivery::factory()->create([
            'workflow_id' => $this->workflow->id,
            'status'      => DeliveryStatus::Failed,
        ]);
        expect($failed->isTerminal())->toBeFalse();
    });
});

describe('N8nDelivery scopes', function() {
    it('filters by direction', function() {
        N8nDelivery::factory()->create(['workflow_id' => $this->workflow->id, 'direction' => DeliveryDirection::Inbound, 'status' => DeliveryStatus::Done]);
        N8nDelivery::factory()->create(['workflow_id' => $this->workflow->id, 'direction' => DeliveryDirection::Outbound, 'status' => DeliveryStatus::Done]);

        expect(N8nDelivery::inbound()->count())->toBe(1)
            ->and(N8nDelivery::outbound()->count())->toBe(1);
    });

    it('filters failed, successful and DLQ deliveries', function() {
        N8nDelivery::factory()->create(['workflow_id' => $this->workflow->id, 'status' => DeliveryStatus::Done]);
        N8nDelivery::factory()->create(['workflow_id' => $this->workflow->id, 'status' => DeliveryStatus::Failed]);
        N8nDelivery::factory()->create(['workflow_id' => $this->workflow->id, 'status' => DeliveryStatus::Dlq]);

        expect(N8nDelivery::failed()->count())->toBe(2)
            ->and(N8nDelivery::successful()->count())->toBe(1)
            ->and(N8nDelivery::dlq()->count())->toBe(1);
    });
});
