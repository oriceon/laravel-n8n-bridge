<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Commands\Queue\QueueCancelCommand;
use Oriceon\N8nBridge\Database\Factories\N8nQueueJobFactory;
use Oriceon\N8nBridge\Database\Factories\N8nWorkflowFactory;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Models\N8nQueueBatch;
use Oriceon\N8nBridge\Models\N8nQueueJob;

covers(QueueCancelCommand::class);

beforeEach(function() {
    $this->workflow = N8nWorkflowFactory::new()->create(['name' => 'test-wf', 'is_active' => true]);
});

describe('n8n:queue:cancel — no arguments', function() {
    it('returns failure when no id, batch or workflow is given', function() {
        $this->artisan('n8n:queue:cancel')
            ->expectsOutputToContain('Specify a job ID')
            ->assertFailed();
    });
});

describe('n8n:queue:cancel — single job by id', function() {
    it('fails when job id is not found', function() {
        $this->artisan('n8n:queue:cancel', ['id' => (string) \Illuminate\Support\Str::uuid()])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    });

    it('refuses to cancel a job in terminal status', function() {
        $done = N8nQueueJobFactory::new()->done()->create(['workflow_id' => $this->workflow->id]);

        $this->artisan('n8n:queue:cancel', ['id' => $done->uuid])
            ->expectsOutputToContain('cannot cancel')
            ->assertFailed();
    });

    it('cancels a pending job', function() {
        $job = N8nQueueJobFactory::new()->pending()->create(['workflow_id' => $this->workflow->id]);

        $this->artisan('n8n:queue:cancel', ['id' => $job->uuid])
            ->expectsOutputToContain('cancelled')
            ->assertSuccessful();

        expect($job->fresh()->status)->toBe(QueueJobStatus::Cancelled);
    });

    it('shows dry-run output without actually cancelling', function() {
        $job = N8nQueueJobFactory::new()->pending()->create(['workflow_id' => $this->workflow->id]);

        $this->artisan('n8n:queue:cancel', ['id' => $job->uuid, '--dry-run' => true])
            ->expectsOutputToContain('dry-run')
            ->assertSuccessful();

        expect($job->fresh()->status)->toBe(QueueJobStatus::Pending);
    });
});

describe('n8n:queue:cancel — by batch', function() {
    it('fails when batch id is not found', function() {
        $this->artisan('n8n:queue:cancel', ['--batch' => 'nonexistent-batch'])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    });

    it('cancels all pending jobs in a batch', function() {
        $batch = N8nQueueBatch::create([
            'uuid'       => (string) \Illuminate\Support\Str::uuid(),
            'name'       => 'test-batch',
            'total_jobs' => 2,
        ]);

        $j1 = N8nQueueJobFactory::new()->pending()->create([
            'workflow_id' => $this->workflow->id,
            'batch_id'    => $batch->id,
        ]);
        $j2 = N8nQueueJobFactory::new()->pending()->create([
            'workflow_id' => $this->workflow->id,
            'batch_id'    => $batch->id,
        ]);

        $this->artisan('n8n:queue:cancel', ['--batch' => $batch->uuid])
            ->expectsOutputToContain('test-batch')
            ->assertSuccessful();

        expect($j1->fresh()->status)->toBe(QueueJobStatus::Cancelled)
            ->and($j2->fresh()->status)->toBe(QueueJobStatus::Cancelled);
    });

    it('shows dry-run count for batch', function() {
        $batch = N8nQueueBatch::create([
            'uuid'       => (string) \Illuminate\Support\Str::uuid(),
            'name'       => 'dry-batch',
            'total_jobs' => 3,
        ]);

        N8nQueueJobFactory::new()->pending()->count(3)->create([
            'workflow_id' => $this->workflow->id,
            'batch_id'    => $batch->id,
        ]);

        $this->artisan('n8n:queue:cancel', ['--batch' => $batch->uuid, '--dry-run' => true])
            ->expectsOutputToContain('[dry-run] Would cancel 3 pending jobs')
            ->assertSuccessful();

        // No jobs should have been cancelled
        expect(N8nQueueJob::where('batch_id', $batch->id)->where('status', QueueJobStatus::Pending->value)->count())->toBe(3);
    });
});

describe('n8n:queue:cancel — by workflow', function() {
    it('reports no pending jobs for a workflow with none', function() {
        $this->artisan('n8n:queue:cancel', ['--workflow' => 'test-wf'])
            ->expectsOutputToContain('No pending jobs')
            ->assertSuccessful();
    });

    it('cancels pending jobs for a workflow after confirmation', function() {
        N8nQueueJobFactory::new()->pending()->count(2)->create(['workflow_id' => $this->workflow->id]);

        $this->artisan('n8n:queue:cancel', ['--workflow' => 'test-wf'])
            ->expectsConfirmation('Cancel 2 job(s) for workflow [test-wf]?', 'yes')
            ->expectsOutputToContain('Cancelled 2 jobs')
            ->assertSuccessful();
    });

    it('shows dry-run output for workflow', function() {
        N8nQueueJobFactory::new()->pending()->count(2)->create(['workflow_id' => $this->workflow->id]);

        $this->artisan('n8n:queue:cancel', ['--workflow' => 'test-wf', '--dry-run' => true])
            ->expectsOutputToContain('dry-run')
            ->assertSuccessful();
    });
});
