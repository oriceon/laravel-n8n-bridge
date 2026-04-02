<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\DeliveryDirection;
use Oriceon\N8nBridge\Enums\StatPeriod;
use Oriceon\N8nBridge\Models\N8nStat;
use Oriceon\N8nBridge\Models\N8nWorkflow;

covers(N8nStat::class);

function makeStat(N8nWorkflow $workflow, array $overrides = []): N8nStat
{
    return N8nStat::create(array_merge([
        'workflow_id'     => $workflow->id,
        'direction'       => DeliveryDirection::Inbound->value,
        'period'          => StatPeriod::Daily->value,
        'period_date'     => now()->toDateString(),
        'total_count'     => 100,
        'success_count'   => 80,
        'failed_count'    => 15,
        'dlq_count'       => 5,
        'skipped_count'   => 0,
        'avg_duration_ms' => 200,
        'p95_duration_ms' => 600,
        'total_bytes_in'  => 1024,
        'total_bytes_out' => 512,
    ], $overrides));
}

beforeEach(function() {
    $this->workflow = N8nWorkflow::factory()->create();
});

// ── successRate() ─────────────────────────────────────────────────────────────

describe('N8nStat::successRate()', function() {
    it('returns correct success rate', function() {
        $stat = makeStat($this->workflow, ['total_count' => 100, 'success_count' => 80]);
        expect($stat->successRate())->toBe(80.0);
    });

    it('returns 0 when total_count is 0', function() {
        $stat = makeStat($this->workflow, ['total_count' => 0, 'success_count' => 0]);
        expect($stat->successRate())->toBe(0.0);
    });
});

// ── failureRate() ─────────────────────────────────────────────────────────────

describe('N8nStat::failureRate()', function() {
    it('returns combined failed + dlq rate', function() {
        $stat = makeStat($this->workflow, [
            'total_count'  => 100,
            'failed_count' => 10,
            'dlq_count'    => 5,
        ]);
        expect($stat->failureRate())->toBe(15.0);
    });

    it('returns 0 when total_count is 0', function() {
        $stat = makeStat($this->workflow, ['total_count' => 0]);
        expect($stat->failureRate())->toBe(0.0);
    });
});

// ── Scopes ────────────────────────────────────────────────────────────────────

describe('N8nStat scopes', function() {
    it('forPeriod() filters by period', function() {
        makeStat($this->workflow, ['period' => StatPeriod::Daily->value]);
        makeStat($this->workflow, [
            'period'      => StatPeriod::Weekly->value,
            'period_date' => now()->startOfWeek()->toDateString(),
            'direction'   => DeliveryDirection::Outbound->value, // avoid unique constraint
        ]);

        expect(N8nStat::forPeriod(StatPeriod::Daily)->count())->toBe(1);
    });

    it('lastDays() returns stats within range', function() {
        makeStat($this->workflow, ['period_date' => now()->subDays(5)->toDateString()]);
        makeStat($this->workflow, [
            'period_date' => now()->subDays(40)->toDateString(),
            'direction'   => DeliveryDirection::Outbound->value,
        ]);

        expect(N8nStat::lastDays(10)->count())->toBe(1);
    });
});

// ── Relations ─────────────────────────────────────────────────────────────────

it('belongs to a workflow', function() {
    $stat = makeStat($this->workflow);
    expect($stat->workflow->id)->toBe($this->workflow->id);
});
