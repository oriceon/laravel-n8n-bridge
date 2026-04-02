<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Database\Factories\N8nQueueJobFactory;
use Oriceon\N8nBridge\Database\Factories\N8nWorkflowFactory;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Models\N8nQueueJob;

covers(N8nQueueJob::class);

describe('estimatedProgressPercent()', function() {

    it('returns 100 when job is Done', function() {
        $job = N8nQueueJobFactory::new()->done()->create();
        expect($job->estimatedProgressPercent())->toBe(100);
    });

    it('returns null for terminal non-done statuses', function(string $status) {
        $job = N8nQueueJobFactory::new()->create(['status' => $status]);
        expect($job->estimatedProgressPercent())->toBeNull();
    })->with([
        QueueJobStatus::Dead->value,
        QueueJobStatus::Cancelled->value,
    ]);

    it('returns 0 when job has not started yet', function() {
        $workflow = N8nWorkflowFactory::new()->create(['estimated_duration_ms' => 5000]);
        $job      = N8nQueueJobFactory::new()->create([
            'workflow_id' => $workflow->id,
            'status'      => QueueJobStatus::Pending->value,
            'started_at'  => null,
        ]);
        expect($job->estimatedProgressPercent())->toBe(0);
    });

    it('returns null when workflow has no duration estimate', function() {
        $workflow = N8nWorkflowFactory::new()->create(['estimated_duration_ms' => null]);
        $job      = N8nQueueJobFactory::new()->create([
            'workflow_id' => $workflow->id,
            'status'      => QueueJobStatus::Running->value,
            'started_at'  => now()->subSeconds(5),
        ]);
        expect($job->estimatedProgressPercent())->toBeNull();
    });

    it('calculates progress based on elapsed time (±5% tolerance)', function() {
        $workflow = N8nWorkflowFactory::new()->create(['estimated_duration_ms' => 10_000]);
        $job      = N8nQueueJobFactory::new()->create([
            'workflow_id' => $workflow->id,
            'status'      => QueueJobStatus::Running->value,
            'started_at'  => now()->subSeconds(5),
        ]);

        expect($job->estimatedProgressPercent())
            ->toBeInt()
            ->toBeGreaterThanOrEqual(40)
            ->toBeLessThanOrEqual(65);
    });

    it('caps at 99 when elapsed time exceeds estimate', function() {
        $workflow = N8nWorkflowFactory::new()->create(['estimated_duration_ms' => 5_000]);
        $job      = N8nQueueJobFactory::new()->create([
            'workflow_id' => $workflow->id,
            'status'      => QueueJobStatus::Running->value,
            'started_at'  => now()->subSeconds(30),
        ]);

        expect($job->estimatedProgressPercent())->toBe(99);
    });

    it('returns near 0 when job just started', function() {
        $workflow = N8nWorkflowFactory::new()->create(['estimated_duration_ms' => 60_000]);
        $job      = N8nQueueJobFactory::new()->create([
            'workflow_id' => $workflow->id,
            'status'      => QueueJobStatus::Running->value,
            'started_at'  => now(),
        ]);

        expect($job->estimatedProgressPercent())
            ->toBeGreaterThanOrEqual(0)
            ->toBeLessThanOrEqual(5);
    });
});
