<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Oriceon\N8nBridge\Commands\Queue\QueuePruneCommand;
use Oriceon\N8nBridge\Database\Factories\N8nQueueJobFactory;
use Oriceon\N8nBridge\Database\Factories\N8nWorkflowFactory;
use Oriceon\N8nBridge\Models\N8nQueueBatch;
use Oriceon\N8nBridge\Models\N8nQueueFailure;
use Oriceon\N8nBridge\Models\N8nQueueJob;

covers(QueuePruneCommand::class);

beforeEach(function(): void {
    $this->workflow = N8nWorkflowFactory::new()->create(['is_active' => true]);
});

describe('n8n:queue:prune', function() {
    it('shows dry-run counts without deleting anything', function() {
        $done = N8nQueueJobFactory::new()->done()->create([
            'workflow_id' => $this->workflow->id,
            'updated_at'  => now()->subDays(60),
        ]);

        $this->artisan('n8n:queue:prune', ['--dry-run' => true, '--days' => '30'])
            ->expectsOutputToContain('[dry-run]')
            ->expectsOutputToContain('Jobs:')
            ->assertSuccessful();

        expect(N8nQueueJob::find($done->id))->not->toBeNull();
    });

    it('deletes done/dead/cancelled jobs older than --days', function() {
        $old = N8nQueueJobFactory::new()->done()->create([
            'workflow_id' => $this->workflow->id,
            'updated_at'  => now()->subDays(60),
        ]);
        $recent = N8nQueueJobFactory::new()->done()->create([
            'workflow_id' => $this->workflow->id,
            'updated_at'  => now()->subDays(1),
        ]);
        $pending = N8nQueueJobFactory::new()->pending()->create([
            'workflow_id' => $this->workflow->id,
            'updated_at'  => now()->subDays(60),
        ]);

        $this->artisan('n8n:queue:prune', ['--days' => '30'])
            ->expectsOutputToContain('Pruned')
            ->assertSuccessful();

        expect(N8nQueueJob::find($old->id))->toBeNull()
            ->and(N8nQueueJob::find($recent->id))->not->toBeNull()
            ->and(N8nQueueJob::find($pending->id))->not->toBeNull();
    });

    it('prunes associated failure records', function() {
        $job = N8nQueueJobFactory::new()->dead()->create([
            'workflow_id' => $this->workflow->id,
            'updated_at'  => now()->subDays(60),
        ]);

        N8nQueueFailure::create([
            'uuid'   => (string) Str::uuid(),
            'job_id' => $job->id,
            'reason' => 'http_5xx',
        ]);

        $this->artisan('n8n:queue:prune', ['--days' => '30'])->assertSuccessful();

        expect(N8nQueueJob::find($job->id))->toBeNull()
            ->and(N8nQueueFailure::where('job_id', $job->id)->count())->toBe(0);
    });

    it('filters by --status option', function() {
        $dead = N8nQueueJobFactory::new()->dead()->create([
            'workflow_id' => $this->workflow->id,
            'updated_at'  => now()->subDays(60),
        ]);
        $done = N8nQueueJobFactory::new()->done()->create([
            'workflow_id' => $this->workflow->id,
            'updated_at'  => now()->subDays(60),
        ]);

        $this->artisan('n8n:queue:prune', ['--days' => '30', '--status' => 'dead'])->assertSuccessful();

        expect(N8nQueueJob::find($dead->id))->toBeNull()
            ->and(N8nQueueJob::find($done->id))->not->toBeNull();
    });

    it('does not delete done jobs newer than --days when delete_done_jobs is true', function() {
        config(['n8n-bridge.queue.delete_done_jobs' => true]);
        config(['n8n-bridge.queue.done_jobs_prune_days' => 1]);

        $recent = N8nQueueJobFactory::new()->done()->create([
            'workflow_id' => $this->workflow->id,
            'updated_at'  => now()->subHours(2), // less than 1 day old
        ]);

        $this->artisan('n8n:queue:prune', ['--days' => '1', '--status' => 'done'])
            ->assertSuccessful();

        expect(N8nQueueJob::find($recent->id))->not->toBeNull();
    });

    it('deletes done jobs older than done_jobs_prune_days when delete_done_jobs is true', function() {
        config(['n8n-bridge.queue.delete_done_jobs' => true]);
        config(['n8n-bridge.queue.done_jobs_prune_days' => 1]);

        $old = N8nQueueJobFactory::new()->done()->create([
            'workflow_id' => $this->workflow->id,
            'updated_at'  => now()->subDays(2),
        ]);

        $this->artisan('n8n:queue:prune', ['--days' => '1', '--status' => 'done'])
            ->assertSuccessful();

        expect(N8nQueueJob::find($old->id))->toBeNull();
    });

    it('prunes finished batches older than --days', function() {
        $oldBatch = N8nQueueBatch::create([
            'uuid'        => (string) Str::uuid(),
            'name'        => 'old-batch',
            'total_jobs'  => 1,
            'finished_at' => now()->subDays(60),
        ]);
        // Force old updated_at directly on the DB to bypass Eloquent timestamp auto-setting
        DB::table($oldBatch->getTable())
            ->where('id', $oldBatch->id)
            ->update(['updated_at' => now()->subDays(60)]);

        $recentBatch = N8nQueueBatch::create([
            'uuid'        => (string) Str::uuid(),
            'name'        => 'recent-batch',
            'total_jobs'  => 1,
            'finished_at' => now()->subDays(1),
        ]);

        $this->artisan('n8n:queue:prune', ['--days' => '30'])->assertSuccessful();

        expect(N8nQueueBatch::find($oldBatch->id))->toBeNull()
            ->and(N8nQueueBatch::find($recentBatch->id))->not->toBeNull();
    });
});
