<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Commands\Queue\QueueRetryCommand;
use Oriceon\N8nBridge\Database\Factories\N8nQueueJobFactory;
use Oriceon\N8nBridge\Database\Factories\N8nWorkflowFactory;
use Oriceon\N8nBridge\Enums\QueueJobPriority;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Models\N8nQueueFailure;
use Oriceon\N8nBridge\Models\N8nQueueJob;

covers(QueueRetryCommand::class);

beforeEach(function() {
    $this->workflow = N8nWorkflowFactory::new()->create(['name' => 'test-wf', 'is_active' => true]);
});

describe('n8n:queue:retry — single job', function() {
    it('fails when job id is not found', function() {
        $this->artisan('n8n:queue:retry', ['id' => (string) \Illuminate\Support\Str::uuid()])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    });

    it('refuses to retry a job that is not dead or failed', function() {
        $pending = N8nQueueJobFactory::new()->pending()->create(['workflow_id' => $this->workflow->id]);

        $this->artisan('n8n:queue:retry', ['id' => $pending->uuid])
            ->expectsOutputToContain('only dead/failed')
            ->assertFailed();
    });

    it('retries a dead job', function() {
        $job = N8nQueueJobFactory::new()->dead()->create(['workflow_id' => $this->workflow->id]);

        $this->artisan('n8n:queue:retry', ['id' => $job->uuid])
            ->expectsOutputToContain('queued for retry')
            ->assertSuccessful();

        expect($job->fresh()->status)->toBe(QueueJobStatus::Pending);
    });

    it('shows dry-run without making changes', function() {
        $job = N8nQueueJobFactory::new()->dead()->create(['workflow_id' => $this->workflow->id]);

        $this->artisan('n8n:queue:retry', ['id' => $job->uuid, '--dry-run' => true])
            ->expectsOutputToContain('dry-run')
            ->assertSuccessful();

        expect($job->fresh()->status)->toBe(QueueJobStatus::Dead);
    });
});

describe('n8n:queue:retry — bulk', function() {
    it('reports no jobs when nothing to retry', function() {
        $this->artisan('n8n:queue:retry')
            ->expectsOutputToContain('No jobs to retry')
            ->assertSuccessful();
    });

    it('retries all dead jobs after confirmation', function() {
        N8nQueueJobFactory::new()->dead()->count(3)->create(['workflow_id' => $this->workflow->id]);

        $this->artisan('n8n:queue:retry')
            ->expectsConfirmation('Retry 3 job(s)?', 'yes')
            ->expectsOutputToContain('3 job(s) queued for retry')
            ->assertSuccessful();

        expect(N8nQueueJob::where('status', QueueJobStatus::Dead->value)->count())->toBe(0);
    });

    it('also includes failed jobs when --failed flag is set', function() {
        N8nQueueJobFactory::new()->dead()->create(['workflow_id' => $this->workflow->id]);
        N8nQueueJobFactory::new()->failed()->create(['workflow_id' => $this->workflow->id]);

        $this->artisan('n8n:queue:retry', ['--failed' => true])
            ->expectsConfirmation('Retry 2 job(s)?', 'yes')
            ->assertSuccessful();
    });

    it('filters by workflow name', function() {
        $other = N8nWorkflowFactory::new()->create(['name' => 'other-wf', 'is_active' => true]);

        N8nQueueJobFactory::new()->dead()->create(['workflow_id' => $this->workflow->id]);
        N8nQueueJobFactory::new()->dead()->create(['workflow_id' => $other->id]);

        $this->artisan('n8n:queue:retry', ['--workflow' => 'test-wf'])
            ->expectsConfirmation('Retry 1 job(s)?', 'yes')
            ->expectsOutputToContain('1 job(s) queued for retry')
            ->assertSuccessful();
    });

    it('fails on unknown failure reason', function() {
        $this->artisan('n8n:queue:retry', ['--reason' => 'bad_reason'])
            ->expectsOutputToContain('Unknown failure reason')
            ->assertFailed();
    });

    it('resets attempts when --reset-attempts is set', function() {
        $job = N8nQueueJobFactory::new()->dead()->create([
            'workflow_id' => $this->workflow->id,
            'attempts'    => 5,
        ]);

        $this->artisan('n8n:queue:retry', ['id' => $job->uuid, '--reset-attempts' => true])
            ->assertSuccessful();

        expect($job->fresh()->attempts)->toBe(0);
    });

    it('overrides priority when --priority is set', function() {
        $job = N8nQueueJobFactory::new()->dead()->create([
            'workflow_id' => $this->workflow->id,
            'priority'    => QueueJobPriority::Normal,
        ]);

        $this->artisan('n8n:queue:retry', ['id' => $job->uuid, '--priority' => 'critical'])
            ->assertSuccessful();

        expect($job->fresh()->priority)->toBe(QueueJobPriority::Critical);
    });

    it('marks failure record as replayed when retrying', function() {
        $job = N8nQueueJobFactory::new()->dead()->create(['workflow_id' => $this->workflow->id]);

        N8nQueueFailure::create([
            'uuid'         => (string) \Illuminate\Support\Str::uuid(),
            'job_id'       => $job->id,
            'reason'       => 'http_5xx',
            'was_replayed' => false,
        ]);

        $this->artisan('n8n:queue:retry', ['id' => $job->uuid])->assertSuccessful();

        expect(N8nQueueFailure::where('job_id', $job->id)->first()->was_replayed)->toBeTrue();
    });
});
