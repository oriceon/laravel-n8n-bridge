<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Commands\Queue\QueueStatusCommand;
use Oriceon\N8nBridge\Database\Factories\N8nQueueJobFactory;
use Oriceon\N8nBridge\Database\Factories\N8nWorkflowFactory;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Models\N8nQueueBatch;

covers(QueueStatusCommand::class);

beforeEach(function() {
    $this->workflow = N8nWorkflowFactory::new()->create(['is_active' => true]);
});

describe('n8n:queue:status', function() {
    it('outputs status overview with no jobs', function() {
        $this->artisan('n8n:queue:status')
            ->expectsOutputToContain('n8n Queue Status')
            ->assertSuccessful();
    });

    it('shows status breakdown when jobs exist', function() {
        N8nQueueJobFactory::new()->pending()->count(3)->create(['workflow_id' => $this->workflow->id]);
        N8nQueueJobFactory::new()->done()->count(2)->create(['workflow_id' => $this->workflow->id]);

        $this->artisan('n8n:queue:status')
            ->expectsOutputToContain('n8n Queue Status')
            ->assertSuccessful();
    });

    it('filters by queue name when --queue option is given', function() {
        N8nQueueJobFactory::new()->pending()->create([
            'workflow_id' => $this->workflow->id,
            'queue_name'  => 'high',
        ]);
        N8nQueueJobFactory::new()->pending()->create([
            'workflow_id' => $this->workflow->id,
            'queue_name'  => 'default',
        ]);

        $this->artisan('n8n:queue:status', ['--queue' => 'high'])
            ->expectsOutputToContain('[high]')
            ->assertSuccessful();
    });

    it('shows active batches section when batches exist', function() {
        N8nQueueBatch::create([
            'uuid'        => (string) \Illuminate\Support\Str::uuid(),
            'name'        => 'My Active Batch',
            'total_jobs'  => 10,
            'cancelled'   => false,
            'finished_at' => null,
        ]);

        $this->artisan('n8n:queue:status')
            ->expectsOutputToContain('Active batches')
            ->expectsOutputToContain('My Active Batch')
            ->assertSuccessful();
    });

    it('warns about stuck jobs when present', function() {
        // Create a "stuck" running job with expired reservation
        N8nQueueJobFactory::new()->create([
            'workflow_id'    => $this->workflow->id,
            'status'         => QueueJobStatus::Running->value,
            'worker_id'      => 'dead-worker:99',
            'reserved_until' => now()->subMinutes(20),
            'queue_name'     => 'default',
        ]);

        $this->artisan('n8n:queue:status')
            ->expectsOutputToContain('stuck jobs')
            ->assertSuccessful();
    });
});
