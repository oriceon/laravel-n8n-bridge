<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Database\Factories\N8nQueueJobFactory;
use Oriceon\N8nBridge\Database\Factories\N8nWorkflowFactory;
use Oriceon\N8nBridge\Queue\Workers\WorkflowDurationUpdater;

covers(WorkflowDurationUpdater::class);

beforeEach(function() {
    $this->updater = app(WorkflowDurationUpdater::class);
});

describe('WorkflowDurationUpdater::record()', function() {

    it('stores the first sample as-is', function() {
        $workflow = N8nWorkflowFactory::new()->create();
        $job      = N8nQueueJobFactory::new()->done()->create([
            'workflow_id' => $workflow->id,
            'duration_ms' => 2000,
        ]);

        $this->updater->record($job);

        expect($workflow->refresh())
            ->estimated_duration_ms->toBe(2000)
            ->estimated_sample_count->toBe(1)
            ->estimated_updated_at->not->toBeNull();
    });

    it('updates EMA on subsequent samples (alpha = 2/11 ≈ 0.182)', function() {
        $workflow = N8nWorkflowFactory::new()->create([
            'estimated_duration_ms'  => 2000,
            'estimated_sample_count' => 10,
        ]);
        $job = N8nQueueJobFactory::new()->done()->create([
            'workflow_id' => $workflow->id,
            'duration_ms' => 3000,
        ]);

        $this->updater->record($job);

        expect($workflow->refresh())
            ->estimated_duration_ms->toBeGreaterThan(2000)
            ->estimated_duration_ms->toBeLessThan(3000)
            ->estimated_sample_count->toBe(11);
    });

    it('caps sample count at configured duration_sample_size', function() {
        config(['n8n-bridge.queue.duration_sample_size' => 50]);

        $workflow = N8nWorkflowFactory::new()->create([
            'estimated_duration_ms'  => 1000,
            'estimated_sample_count' => 50,
        ]);
        $job = N8nQueueJobFactory::new()->done()->create([
            'workflow_id' => $workflow->id,
            'duration_ms' => 1100,
        ]);

        $this->updater->record($job);

        expect($workflow->refresh()->estimated_sample_count)->toBe(50);
    });

    it('skips update when duration_ms is null', function() {
        $workflow = N8nWorkflowFactory::new()->create(['estimated_duration_ms' => 1500]);
        $job      = N8nQueueJobFactory::new()->done()->create([
            'workflow_id' => $workflow->id,
            'duration_ms' => null,
        ]);

        $this->updater->record($job);

        expect($workflow->refresh()->estimated_duration_ms)->toBe(1500);
    });

    it('skips update when duration_ms is zero', function() {
        $workflow = N8nWorkflowFactory::new()->create(['estimated_duration_ms' => 1500]);
        $job      = N8nQueueJobFactory::new()->done()->create([
            'workflow_id' => $workflow->id,
            'duration_ms' => 0,
        ]);

        $this->updater->record($job);

        expect($workflow->refresh()->estimated_duration_ms)->toBe(1500);
    });
});

describe('WorkflowDurationUpdater::reset()', function() {

    it('clears all estimation fields', function() {
        $workflow = N8nWorkflowFactory::new()->create([
            'estimated_duration_ms'  => 3000,
            'estimated_sample_count' => 20,
        ]);

        $this->updater->reset($workflow);

        expect($workflow->refresh())
            ->estimated_duration_ms->toBeNull()
            ->estimated_sample_count->toBe(0)
            ->estimated_updated_at->toBeNull();
    });
});

describe('WorkflowDurationUpdater::recalculate()', function() {

    it('calculates simple average from completed jobs', function() {
        $workflow = N8nWorkflowFactory::new()->create();

        foreach ([1000, 2000, 3000, 4000, 5000] as $ms) {
            N8nQueueJobFactory::new()->done()->create([
                'workflow_id' => $workflow->id,
                'duration_ms' => $ms,
                'finished_at' => now(),
            ]);
        }

        $this->updater->recalculate($workflow);

        expect($workflow->refresh())
            ->estimated_duration_ms->toBe(3000)
            ->estimated_sample_count->toBe(5);
    });
});

describe('N8nWorkflow duration helpers', function() {

    it('hasEstimatedDuration() returns false without data', function() {
        $workflow = N8nWorkflowFactory::new()->create(['estimated_duration_ms' => null]);
        expect($workflow->hasEstimatedDuration())->toBeFalse();
    });

    it('hasEstimatedDuration() returns true with data', function() {
        $workflow = N8nWorkflowFactory::new()->create(['estimated_duration_ms' => 2500]);
        expect($workflow->hasEstimatedDuration())->toBeTrue();
    });

    it('estimatedDurationLabel() formats seconds and minutes', function() {
        $seconds = N8nWorkflowFactory::new()->create(['estimated_duration_ms' => 2300]);
        $minutes = N8nWorkflowFactory::new()->create(['estimated_duration_ms' => 90_000]);
        $none    = N8nWorkflowFactory::new()->create(['estimated_duration_ms' => null]);

        expect($seconds->estimatedDurationLabel())->toBe('~2.3s')
            ->and($minutes->estimatedDurationLabel())->toBe('~1.5m')
            ->and($none->estimatedDurationLabel())->toBeNull();
    });
});
