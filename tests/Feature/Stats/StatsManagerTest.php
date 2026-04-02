<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use Oriceon\N8nBridge\Enums\DeliveryDirection;
use Oriceon\N8nBridge\Enums\DeliveryStatus;
use Oriceon\N8nBridge\Enums\StatPeriod;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nStat;
use Oriceon\N8nBridge\Models\N8nWorkflow;
use Oriceon\N8nBridge\Stats\StatsManager;

covers(StatsManager::class);

beforeEach(function() {
    Carbon::setTestNow('2026-03-25');

    $this->manager  = app(StatsManager::class);
    $this->workflow = N8nWorkflow::factory()->create(['name' => 'invoice-paid']);
});

// ── overview() ────────────────────────────────────────────────────────────────

describe('StatsManager::overview()', function() {
    it('returns zeroes with no deliveries', function() {
        $overview = $this->manager->overview();

        expect($overview['total_deliveries'])->toBe(0)
            ->and($overview['success_count'])->toBe(0)
            ->and($overview['failed_count'])->toBe(0)
            ->and($overview['dlq_pending'])->toBe(0)
            ->and($overview['success_rate'])->toBe(0.0)
            ->and($overview['avg_duration_ms'])->toBe(0.0);
    });

    it('counts total, success, failed and dlq correctly', function() {
        N8nDelivery::factory()->create(['workflow_id' => $this->workflow->id, 'status' => DeliveryStatus::Done, 'duration_ms' => 100]);
        N8nDelivery::factory()->create(['workflow_id' => $this->workflow->id, 'status' => DeliveryStatus::Done, 'duration_ms' => 300]);
        N8nDelivery::factory()->create(['workflow_id' => $this->workflow->id, 'status' => DeliveryStatus::Failed]);
        N8nDelivery::factory()->create(['workflow_id' => $this->workflow->id, 'status' => DeliveryStatus::Dlq]);

        $overview = $this->manager->overview();

        expect($overview['total_deliveries'])->toBe(4)
            ->and($overview['success_count'])->toBe(2)
            ->and($overview['failed_count'])->toBe(2)  // Failed + Dlq
            ->and($overview['dlq_pending'])->toBe(1)
            ->and($overview['success_rate'])->toBe(50.0)
            ->and($overview['avg_duration_ms'])->toBe(200.0);
    });

    it('includes failed_today count', function() {
        N8nDelivery::factory()->create(['workflow_id' => $this->workflow->id, 'status' => DeliveryStatus::Failed]);

        $overview = $this->manager->overview();

        expect($overview['failed_today'])->toBe(1);
    });
});

// ── topFailed() ───────────────────────────────────────────────────────────────

describe('StatsManager::topFailed()', function() {
    it('returns workflows ordered by failure count', function() {
        $wfA = N8nWorkflow::factory()->create();
        $wfB = N8nWorkflow::factory()->create();

        N8nDelivery::factory()->count(3)->create(['workflow_id' => $wfA->id, 'status' => DeliveryStatus::Failed]);
        N8nDelivery::factory()->count(1)->create(['workflow_id' => $wfB->id, 'status' => DeliveryStatus::Dlq]);

        $top = $this->manager->topFailed(10);

        expect($top->first()['workflow_id'])->toBe($wfA->id);
    });

    it('respects the limit parameter', function() {
        foreach (range(1, 5) as $i) {
            $wf = N8nWorkflow::factory()->create();
            N8nDelivery::factory()->count($i)->create(['workflow_id' => $wf->id, 'status' => DeliveryStatus::Failed]);
        }

        expect($this->manager->topFailed(3))->toHaveCount(3);
    });

    it('returns empty collection when no failures', function() {
        N8nDelivery::factory()->create(['workflow_id' => $this->workflow->id, 'status' => DeliveryStatus::Done]);

        expect($this->manager->topFailed())->toBeEmpty();
    });
});

// ── Fluent filters ────────────────────────────────────────────────────────────

describe('StatsManager fluent query', function() {
    beforeEach(function() {
        N8nStat::create([
            'workflow_id'     => $this->workflow->id,
            'direction'       => DeliveryDirection::Inbound->value,
            'period'          => StatPeriod::Daily->value,
            'period_date'     => '2026-03-20',
            'total_count'     => 10,
            'success_count'   => 8,
            'failed_count'    => 2,
            'dlq_count'       => 0,
            'skipped_count'   => 0,
            'avg_duration_ms' => 120,
            'p95_duration_ms' => 400,
            'total_bytes_in'  => 1024,
            'total_bytes_out' => 512,
        ]);

        N8nStat::create([
            'workflow_id'     => $this->workflow->id,
            'direction'       => DeliveryDirection::Outbound->value,
            'period'          => StatPeriod::Daily->value,
            'period_date'     => '2026-03-21',
            'total_count'     => 5,
            'success_count'   => 5,
            'failed_count'    => 0,
            'dlq_count'       => 0,
            'skipped_count'   => 0,
            'avg_duration_ms' => 80,
            'p95_duration_ms' => 200,
            'total_bytes_in'  => 256,
            'total_bytes_out' => 128,
        ]);
    });

    it('forWorkflow filters by model', function() {
        $other = N8nWorkflow::factory()->create();
        N8nStat::create([
            'workflow_id'     => $other->id, 'direction' => DeliveryDirection::Inbound->value,
            'period'          => StatPeriod::Daily->value, 'period_date' => '2026-03-20',
            'total_count'     => 1, 'success_count' => 1, 'failed_count' => 0,
            'dlq_count'       => 0, 'skipped_count' => 0, 'avg_duration_ms' => 0,
            'p95_duration_ms' => 0, 'total_bytes_in' => 0, 'total_bytes_out' => 0,
        ]);

        $results = $this->manager->forWorkflow($this->workflow)->get();
        expect($results)->toHaveCount(2);
    });

    it('forWorkflow accepts workflow ID string', function() {
        $results = $this->manager->forWorkflow((string) $this->workflow->id)->get();
        expect($results)->toHaveCount(2);
    });

    it('period() filters by stat period', function() {
        $results = $this->manager->period(StatPeriod::Daily)->get();
        expect($results)->toHaveCount(2);
    });

    it('direction() filters by delivery direction', function() {
        $results = $this->manager->direction(DeliveryDirection::Inbound)->get();
        expect($results)->toHaveCount(1)
            ->and($results->first()->direction)->toBe(DeliveryDirection::Inbound);
    });

    it('between() filters by date range', function() {
        $results = $this->manager->between('2026-03-20', '2026-03-20')->get();
        expect($results)->toHaveCount(1);
    });

    it('lastDays() returns only recent records', function() {
        // The seeded records (2026-03-20, 2026-03-21) are in the past from "today" (2026-03-25)
        // lastDays(10) = from 2026-03-15 → should include both
        $results = $this->manager->lastDays(10)->get();
        expect($results)->toHaveCount(2);
    });

    it('returns records ordered by period_date', function() {
        $results = $this->manager->get();
        expect($results->first()->period_date->toDateString())->toBe('2026-03-20')
            ->and($results->last()->period_date->toDateString())->toBe('2026-03-21');
    });
});

// ── toChartData() ─────────────────────────────────────────────────────────────

describe('StatsManager::toChartData()', function() {
    it('returns chart-ready structure with correct keys', function() {
        N8nStat::create([
            'workflow_id'     => $this->workflow->id, 'direction' => DeliveryDirection::Inbound->value,
            'period'          => StatPeriod::Daily->value, 'period_date' => '2026-03-24',
            'total_count'     => 10, 'success_count' => 8, 'failed_count' => 1,
            'dlq_count'       => 1, 'skipped_count' => 0, 'avg_duration_ms' => 200,
            'p95_duration_ms' => 500, 'total_bytes_in' => 0, 'total_bytes_out' => 0,
        ]);

        $chart = $this->manager->forWorkflow($this->workflow)->toChartData();

        expect($chart)->toHaveKeys(['labels', 'success', 'failed', 'dlq', 'success_rate', 'avg_ms'])
            ->and($chart['labels'])->toHaveCount(1)
            ->and($chart['success'][0])->toBe(8)
            ->and($chart['failed'][0])->toBe(1);
    });

    it('returns empty arrays when no stats', function() {
        $chart = $this->manager->forWorkflow($this->workflow)->toChartData();

        expect($chart['labels'])->toBe([])
            ->and($chart['success'])->toBe([]);
    });
});
