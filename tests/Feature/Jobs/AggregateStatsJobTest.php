<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Oriceon\N8nBridge\Database\Factories\N8nQueueJobFactory;
use Oriceon\N8nBridge\Enums\DeliveryDirection;
use Oriceon\N8nBridge\Enums\DeliveryStatus;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Enums\StatPeriod;
use Oriceon\N8nBridge\Jobs\AggregateN8nStatsJob;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nStat;
use Oriceon\N8nBridge\Models\N8nWorkflow;
use Oriceon\N8nBridge\Notifications\N8nAlertNotifiable;
use Oriceon\N8nBridge\Notifications\N8nAlertNotification;

covers(AggregateN8nStatsJob::class);

beforeEach(function() {
    $this->workflow = N8nWorkflow::factory()->create(['is_active' => true]);
    $this->date     = '2026-03-24';
});

// ── Aggregation ────────────────────────────────────────────────────────────────

describe('AggregateN8nStatsJob aggregation', function() {
    it('creates a stat record for the given date and direction', function() {
        N8nDelivery::factory()->count(5)->create([
            'workflow_id' => $this->workflow->id,
            'direction'   => DeliveryDirection::Outbound->value,
            'status'      => DeliveryStatus::Done->value,
            'created_at'  => $this->date . ' 12:00:00',
        ]);

        dispatch_sync(new AggregateN8nStatsJob($this->date));

        $stat = N8nStat::where('workflow_id', $this->workflow->id)
            ->whereDate('period_date', $this->date)
            ->where('period', StatPeriod::Daily->value)
            ->first();

        expect($stat)->not->toBeNull()
            ->and($stat->total_count)->toBe(5)
            ->and($stat->success_count)->toBe(5)
            ->and($stat->failed_count)->toBe(0);
    });

    it('counts failed and dlq deliveries correctly', function() {
        N8nDelivery::factory()->create(['workflow_id' => $this->workflow->id, 'direction' => DeliveryDirection::Inbound->value, 'status' => DeliveryStatus::Done->value, 'created_at' => $this->date . ' 10:00:00']);
        N8nDelivery::factory()->create(['workflow_id' => $this->workflow->id, 'direction' => DeliveryDirection::Inbound->value, 'status' => DeliveryStatus::Failed->value, 'created_at' => $this->date . ' 10:00:00']);
        N8nDelivery::factory()->create(['workflow_id' => $this->workflow->id, 'direction' => DeliveryDirection::Inbound->value, 'status' => DeliveryStatus::Dlq->value, 'created_at' => $this->date . ' 10:00:00']);

        dispatch_sync(new AggregateN8nStatsJob($this->date));

        $stat = N8nStat::where('workflow_id', $this->workflow->id)
            ->where('direction', DeliveryDirection::Inbound->value)
            ->whereDate('period_date', $this->date)
            ->first();

        expect($stat->total_count)->toBe(3)
            ->and($stat->failed_count)->toBe(1)
            ->and($stat->dlq_count)->toBe(1);
    });

    it('groups by direction — inbound and outbound are separate', function() {
        N8nDelivery::factory()->create(['workflow_id' => $this->workflow->id, 'direction' => DeliveryDirection::Inbound->value, 'status' => DeliveryStatus::Done->value, 'created_at' => $this->date . ' 10:00:00']);
        N8nDelivery::factory()->create(['workflow_id' => $this->workflow->id, 'direction' => DeliveryDirection::Outbound->value, 'status' => DeliveryStatus::Done->value, 'created_at' => $this->date . ' 10:00:00']);

        dispatch_sync(new AggregateN8nStatsJob($this->date));

        expect(
            N8nStat::where('workflow_id', $this->workflow->id)->whereDate('period_date', $this->date)->count()
        )->toBe(2);
    });

    it('skips workflows with no deliveries for the date', function() {
        $emptyWorkflow = N8nWorkflow::factory()->create(['is_active' => true]);

        N8nDelivery::factory()->create([
            'workflow_id' => $this->workflow->id,
            'direction'   => DeliveryDirection::Inbound->value,
            'status'      => DeliveryStatus::Done->value,
            'created_at'  => $this->date . ' 10:00:00',
        ]);

        dispatch_sync(new AggregateN8nStatsJob($this->date));

        expect(
            N8nStat::where('workflow_id', $emptyWorkflow->id)->whereDate('period_date', $this->date)->count()
        )->toBe(0);
    });

    it('upserts — running twice does not duplicate records', function() {
        N8nDelivery::factory()->create([
            'workflow_id' => $this->workflow->id,
            'direction'   => DeliveryDirection::Outbound->value,
            'status'      => DeliveryStatus::Done->value,
            'created_at'  => $this->date . ' 10:00:00',
        ]);

        dispatch_sync(new AggregateN8nStatsJob($this->date));
        dispatch_sync(new AggregateN8nStatsJob($this->date));

        expect(
            N8nStat::where('workflow_id', $this->workflow->id)->whereDate('period_date', $this->date)->count()
        )->toBe(1);
    });

    it('calculates avg_duration_ms for done deliveries', function() {
        foreach ([100, 200, 300] as $ms) {
            N8nDelivery::factory()->create([
                'workflow_id' => $this->workflow->id,
                'direction'   => DeliveryDirection::Outbound->value,
                'status'      => DeliveryStatus::Done->value,
                'duration_ms' => $ms,
                'created_at'  => $this->date . ' 10:00:00',
            ]);
        }

        dispatch_sync(new AggregateN8nStatsJob($this->date));

        $stat = N8nStat::where('workflow_id', $this->workflow->id)
            ->where('direction', DeliveryDirection::Outbound->value)
            ->whereDate('period_date', $this->date)
            ->first();

        expect($stat->avg_duration_ms)->toBe(200);
    });
});

// ── Queue stats aggregation ────────────────────────────────────────────────────

describe('AggregateN8nStatsJob queue stats', function() {
    it('creates a queue stat record with direction=queue', function() {
        N8nQueueJobFactory::new()->done()->create([
            'workflow_id' => $this->workflow->id,
            'created_at'  => $this->date . ' 10:00:00',
            'duration_ms' => 500,
        ]);

        dispatch_sync(new AggregateN8nStatsJob($this->date));

        $stat = N8nStat::where('workflow_id', $this->workflow->id)
            ->where('direction', DeliveryDirection::Queue->value)
            ->whereDate('period_date', $this->date)
            ->where('period', StatPeriod::Daily->value)
            ->first();

        expect($stat)->not->toBeNull()
            ->and($stat->total_count)->toBe(1)
            ->and($stat->success_count)->toBe(1)
            ->and($stat->failed_count)->toBe(0)
            ->and($stat->dlq_count)->toBe(0);
    });

    it('counts done, dead, failed, and cancelled queue jobs correctly', function() {
        N8nQueueJobFactory::new()->done()->create(['workflow_id' => $this->workflow->id, 'created_at' => $this->date . ' 10:00:00']);
        N8nQueueJobFactory::new()->done()->create(['workflow_id' => $this->workflow->id, 'created_at' => $this->date . ' 10:00:00']);
        N8nQueueJobFactory::new()->dead()->create(['workflow_id' => $this->workflow->id, 'created_at' => $this->date . ' 10:00:00']);
        N8nQueueJobFactory::new()->create(['workflow_id' => $this->workflow->id, 'status' => QueueJobStatus::Cancelled->value, 'created_at' => $this->date . ' 10:00:00']);

        dispatch_sync(new AggregateN8nStatsJob($this->date));

        $stat = N8nStat::where('workflow_id', $this->workflow->id)
            ->where('direction', DeliveryDirection::Queue->value)
            ->whereDate('period_date', $this->date)
            ->first();

        expect($stat->total_count)->toBe(4)
            ->and($stat->success_count)->toBe(2)
            ->and($stat->dlq_count)->toBe(1)
            ->and($stat->skipped_count)->toBe(1);
    });

    it('counts dead jobs in both failed_count and dlq_count', function() {
        N8nQueueJobFactory::new()->dead()->create(['workflow_id' => $this->workflow->id, 'created_at' => $this->date . ' 10:00:00']);

        dispatch_sync(new AggregateN8nStatsJob($this->date));

        $stat = N8nStat::where('workflow_id', $this->workflow->id)
            ->where('direction', DeliveryDirection::Queue->value)
            ->whereDate('period_date', $this->date)
            ->first();

        expect($stat->failed_count)->toBe(1)
            ->and($stat->dlq_count)->toBe(1);
    });

    it('calculates avg_duration_ms from done jobs only', function() {
        foreach ([200, 400, 600] as $ms) {
            N8nQueueJobFactory::new()->done()->create([
                'workflow_id' => $this->workflow->id,
                'created_at'  => $this->date . ' 10:00:00',
                'duration_ms' => $ms,
            ]);
        }

        // Dead job — should not affect duration calculation
        N8nQueueJobFactory::new()->dead()->create([
            'workflow_id' => $this->workflow->id,
            'created_at'  => $this->date . ' 10:00:00',
        ]);

        dispatch_sync(new AggregateN8nStatsJob($this->date));

        $stat = N8nStat::where('workflow_id', $this->workflow->id)
            ->where('direction', DeliveryDirection::Queue->value)
            ->whereDate('period_date', $this->date)
            ->first();

        expect($stat->avg_duration_ms)->toBe(400);
    });

    it('skips workflows with no queue jobs for the date', function() {
        $emptyWorkflow = N8nWorkflow::factory()->create(['is_active' => true]);

        N8nQueueJobFactory::new()->done()->create([
            'workflow_id' => $this->workflow->id,
            'created_at'  => $this->date . ' 10:00:00',
        ]);

        dispatch_sync(new AggregateN8nStatsJob($this->date));

        expect(
            N8nStat::where('workflow_id', $emptyWorkflow->id)
                ->where('direction', DeliveryDirection::Queue->value)
                ->whereDate('period_date', $this->date)
                ->count()
        )->toBe(0);
    });

    it('upserts queue stats — running twice does not duplicate records', function() {
        N8nQueueJobFactory::new()->done()->create([
            'workflow_id' => $this->workflow->id,
            'created_at'  => $this->date . ' 10:00:00',
        ]);

        dispatch_sync(new AggregateN8nStatsJob($this->date));
        dispatch_sync(new AggregateN8nStatsJob($this->date));

        expect(
            N8nStat::where('workflow_id', $this->workflow->id)
                ->where('direction', DeliveryDirection::Queue->value)
                ->whereDate('period_date', $this->date)
                ->count()
        )->toBe(1);
    });

    it('queue stats and delivery stats are stored as separate records', function() {
        N8nQueueJobFactory::new()->done()->create([
            'workflow_id' => $this->workflow->id,
            'created_at'  => $this->date . ' 10:00:00',
        ]);
        N8nDelivery::factory()->create([
            'workflow_id' => $this->workflow->id,
            'direction'   => DeliveryDirection::Outbound->value,
            'status'      => DeliveryStatus::Done->value,
            'created_at'  => $this->date . ' 10:00:00',
        ]);

        dispatch_sync(new AggregateN8nStatsJob($this->date));

        $directions = N8nStat::where('workflow_id', $this->workflow->id)
            ->whereDate('period_date', $this->date)
            ->pluck('direction')
            ->toArray();

        expect($directions)->toContain(DeliveryDirection::Queue)
            ->toContain(DeliveryDirection::Outbound);
    });

    it('sends notification when dead-job rate exceeds threshold', function() {
        config(['n8n-bridge.notifications.enabled' => true]);
        config(['n8n-bridge.notifications.error_rate_threshold' => 20.0]);
        config(['n8n-bridge.notifications.channels' => ['mail']]);
        config(['n8n-bridge.notifications.mail_to' => 'ops@example.com']);

        Notification::fake();

        // 1 done, 4 dead → 80% dead rate
        N8nQueueJobFactory::new()->done()->create(['workflow_id' => $this->workflow->id, 'created_at' => $this->date . ' 10:00:00']);
        N8nQueueJobFactory::new()->dead()->count(4)->create(['workflow_id' => $this->workflow->id, 'created_at' => $this->date . ' 10:00:00']);

        dispatch_sync(new AggregateN8nStatsJob($this->date));

        Notification::assertSentTo([new N8nAlertNotifiable()], N8nAlertNotification::class);
    });
});

// ── Error rate notifications ───────────────────────────────────────────────────

describe('AggregateN8nStatsJob error rate notification', function() {

    it('sends notification when error rate exceeds threshold', function() {
        config(['n8n-bridge.notifications.enabled' => true]);
        config(['n8n-bridge.notifications.error_rate_threshold' => 20.0]);
        config(['n8n-bridge.notifications.channels' => ['mail']]);
        config(['n8n-bridge.notifications.mail_to' => 'ops@example.com']);

        Notification::fake();

        // 5 done, 5 failed → 50% error rate
        N8nDelivery::factory()->count(5)->create(['workflow_id' => $this->workflow->id, 'direction' => DeliveryDirection::Inbound->value, 'status' => DeliveryStatus::Done->value, 'created_at' => $this->date . ' 10:00:00']);
        N8nDelivery::factory()->count(5)->create(['workflow_id' => $this->workflow->id, 'direction' => DeliveryDirection::Inbound->value, 'status' => DeliveryStatus::Failed->value, 'created_at' => $this->date . ' 10:00:00']);

        dispatch_sync(new AggregateN8nStatsJob($this->date));

        Notification::assertSentTo([new N8nAlertNotifiable()], N8nAlertNotification::class);
    });

    it('does not send notification below threshold', function() {
        config(['n8n-bridge.notifications.enabled' => true]);
        config(['n8n-bridge.notifications.error_rate_threshold' => 50.0]);

        Notification::fake();

        // 9 done, 1 failed → 10% error rate
        N8nDelivery::factory()->count(9)->create(['workflow_id' => $this->workflow->id, 'direction' => DeliveryDirection::Inbound->value, 'status' => DeliveryStatus::Done->value, 'created_at' => $this->date . ' 10:00:00']);
        N8nDelivery::factory()->count(1)->create(['workflow_id' => $this->workflow->id, 'direction' => DeliveryDirection::Inbound->value, 'status' => DeliveryStatus::Failed->value, 'created_at' => $this->date . ' 10:00:00']);

        dispatch_sync(new AggregateN8nStatsJob($this->date));

        Notification::assertNothingSent();
    });
});
