<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Database\Factories\N8nQueueJobFactory;
use Oriceon\N8nBridge\Database\Factories\N8nWorkflowFactory;
use Oriceon\N8nBridge\Enums\CheckpointStatus;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Models\N8nQueueCheckpoint;
use Oriceon\N8nBridge\Observers\N8nQueueJobObserver;

covers(N8nQueueJobObserver::class);

function seedCheckpoints(int $jobId, int $count = 3): void
{
    foreach (range(1, $count) as $i) {
        N8nQueueCheckpoint::create([
            'job_id'    => $jobId,
            'node_name' => "node_{$i}",
            'status'    => CheckpointStatus::Completed,
            'sequence'  => $i,
        ]);
    }
}

describe('Checkpoint cleanup on Done', function() {

    it('deletes checkpoints when delete_checkpoints_on_success is enabled', function() {
        config(['n8n-bridge.queue.delete_checkpoints_on_success' => true]);

        $workflow = N8nWorkflowFactory::new()->create(['estimated_duration_ms' => null]);
        $job      = N8nQueueJobFactory::new()->create([
            'workflow_id' => $workflow->id,
            'status'      => QueueJobStatus::Running->value,
            'started_at'  => now()->subSeconds(2),
            'duration_ms' => null,
        ]);

        seedCheckpoints($job->id, 4);
        expect(N8nQueueCheckpoint::where('job_id', $job->id)->count())->toBe(4);

        $job->update(['status' => QueueJobStatus::Done->value, 'finished_at' => now(), 'duration_ms' => 2000]);

        expect(N8nQueueCheckpoint::where('job_id', $job->id)->count())->toBe(0);
    });

    it('preserves checkpoints when delete_checkpoints_on_success is disabled', function() {
        config(['n8n-bridge.queue.delete_checkpoints_on_success' => false]);

        $workflow = N8nWorkflowFactory::new()->create();
        $job      = N8nQueueJobFactory::new()->create([
            'workflow_id' => $workflow->id,
            'status'      => QueueJobStatus::Running->value,
            'started_at'  => now()->subSeconds(2),
        ]);

        seedCheckpoints($job->id, 3);

        $job->update(['status' => QueueJobStatus::Done->value, 'finished_at' => now(), 'duration_ms' => 1500]);

        expect(N8nQueueCheckpoint::where('job_id', $job->id)->count())->toBe(3);
    });

    it('preserves checkpoints when job fails (needed for debugging)', function() {
        config(['n8n-bridge.queue.delete_checkpoints_on_success' => true]);

        $job = N8nQueueJobFactory::new()->create([
            'status'     => QueueJobStatus::Running->value,
            'started_at' => now()->subSeconds(5),
        ]);
        seedCheckpoints($job->id, 2);

        $job->update(['status' => QueueJobStatus::Failed->value, 'last_error_message' => 'n8n returned 500']);

        expect(N8nQueueCheckpoint::where('job_id', $job->id)->count())->toBe(2);
    });
});

describe('EMA duration update on Done', function() {

    it('sets estimated_duration_ms on first completion', function() {
        config(['n8n-bridge.queue.delete_checkpoints_on_success' => true]);

        $workflow = N8nWorkflowFactory::new()->create([
            'estimated_duration_ms'  => null,
            'estimated_sample_count' => 0,
        ]);
        $job = N8nQueueJobFactory::new()->create([
            'workflow_id' => $workflow->id,
            'status'      => QueueJobStatus::Running->value,
            'started_at'  => now()->subSeconds(3),
            'duration_ms' => null,
        ]);

        $job->update(['status' => QueueJobStatus::Done->value, 'finished_at' => now(), 'duration_ms' => 3000]);

        expect($workflow->refresh())
            ->estimated_duration_ms->toBe(3000)
            ->estimated_sample_count->toBe(1);
    });

    it('does not update duration when job fails', function() {
        $workflow = N8nWorkflowFactory::new()->create([
            'estimated_duration_ms'  => 5000,
            'estimated_sample_count' => 10,
        ]);
        $job = N8nQueueJobFactory::new()->create([
            'workflow_id' => $workflow->id,
            'status'      => QueueJobStatus::Running->value,
        ]);

        $job->update(['status' => QueueJobStatus::Failed->value, 'last_error_message' => 'Timeout']);

        expect($workflow->refresh())
            ->estimated_duration_ms->toBe(5000)
            ->estimated_sample_count->toBe(10);
    });
});
