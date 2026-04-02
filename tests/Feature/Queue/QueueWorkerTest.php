<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Oriceon\N8nBridge\Database\Factories\N8nQueueJobFactory;
use Oriceon\N8nBridge\Database\Factories\N8nWorkflowFactory;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Events\N8nQueueJobCompletedEvent;
use Oriceon\N8nBridge\Events\N8nQueueJobFailedEvent;
use Oriceon\N8nBridge\Events\N8nQueueJobStartedEvent;
use Oriceon\N8nBridge\Models\N8nQueueFailure;
use Oriceon\N8nBridge\Outbound\OutboundRateLimiter;
use Oriceon\N8nBridge\Queue\Workers\QueueWorker;

covers(QueueWorker::class);

beforeEach(function() {
    $this->worker   = app(QueueWorker::class);
    $this->workflow = N8nWorkflowFactory::new()->create([
        'webhook_path' => 'invoice-reminder',
        'is_active'    => true,
    ]);
    config(['n8n-bridge.outbound.rate_limit' => 0]); // unlimited by default
    app(OutboundRateLimiter::class)->reset($this->workflow);
});

// ── recoverStuckJobs() ────────────────────────────────────────────────────────

describe('QueueWorker::recoverStuckJobs()', function() {
    it('resets stuck claimed jobs back to pending', function() {
        $stuck = N8nQueueJobFactory::new()->create([
            'workflow_id'    => $this->workflow->id,
            'status'         => QueueJobStatus::Running->value,
            'worker_id'      => 'old-worker:123',
            'reserved_until' => now()->subMinutes(20),
            'queue_name'     => 'default',
        ]);

        $count = $this->worker->recoverStuckJobs('default');

        expect($count)->toBe(1)
            ->and($stuck->fresh()->status)->toBe(QueueJobStatus::Pending)
            ->and($stuck->fresh()->worker_id)->toBeNull()
            ->and($stuck->fresh()->reserved_until)->toBeNull();
    });

    it('does not touch fresh jobs', function() {
        $fresh = N8nQueueJobFactory::new()->create([
            'workflow_id'    => $this->workflow->id,
            'status'         => QueueJobStatus::Running->value,
            'reserved_until' => now()->addMinutes(5),
            'queue_name'     => 'default',
        ]);

        $count = $this->worker->recoverStuckJobs('default');

        expect($count)->toBe(0)
            ->and($fresh->fresh()->status)->toBe(QueueJobStatus::Running);
    });

    it('does not touch pending jobs', function() {
        N8nQueueJobFactory::new()->create([
            'workflow_id' => $this->workflow->id,
            'status'      => QueueJobStatus::Pending->value,
            'queue_name'  => 'default',
        ]);

        expect($this->worker->recoverStuckJobs('default'))->toBe(0);
    });

    it('only recovers jobs from the specified queue', function() {
        N8nQueueJobFactory::new()->create([
            'workflow_id'    => $this->workflow->id,
            'status'         => QueueJobStatus::Running->value,
            'reserved_until' => now()->subMinutes(20),
            'queue_name'     => 'other-queue',
        ]);

        expect($this->worker->recoverStuckJobs('default'))->toBe(0);
    });

    it('recovers multiple stuck jobs', function() {
        N8nQueueJobFactory::new()->count(3)->create([
            'workflow_id'    => $this->workflow->id,
            'status'         => QueueJobStatus::Claimed->value,
            'reserved_until' => now()->subMinutes(15),
            'queue_name'     => 'default',
        ]);

        expect($this->worker->recoverStuckJobs('default'))->toBe(3);
    });

    it('sets available_at to a brief future delay after recovery', function() {
        $stuck = N8nQueueJobFactory::new()->create([
            'workflow_id'    => $this->workflow->id,
            'status'         => QueueJobStatus::Running->value,
            'reserved_until' => now()->subMinutes(20),
            'queue_name'     => 'default',
        ]);

        $this->worker->recoverStuckJobs('default');

        expect($stuck->fresh()->available_at?->isFuture())->toBeTrue();
    });
});

// ── Worker state ─────────────────────────────────────────────────────────────

describe('QueueWorker state', function() {
    it('starts with shouldStop = false', function() {
        expect($this->worker->shouldStop())->toBeFalse();
    });

    it('has a non-empty workerId', function() {
        expect($this->worker->workerId())->toBeString()->not->toBeEmpty();
    });

    it('workerId is stable across calls', function() {
        expect($this->worker->workerId())->toBe($this->worker->workerId());
    });
});

// ── Job processing via run() with maxJobs=1 ───────────────────────────────────

describe('QueueWorker::run() with HTTP fake', function() {
    it('marks job Done on successful n8n call', function() {
        Http::fake(['*/webhook*/*' => Http::response(['execution_id' => 123], 200)]);
        Event::fake([N8nQueueJobStartedEvent::class, N8nQueueJobCompletedEvent::class]);

        $job = N8nQueueJobFactory::new()->create([
            'workflow_id' => $this->workflow->id,
            'queue_name'  => 'default',
            'status'      => QueueJobStatus::Pending->value,
        ]);

        $this->worker->run('default', sleep: 0, maxJobs: 1);

        expect($job->fresh()->status)->toBe(QueueJobStatus::Done)
            ->and($job->fresh()->n8n_execution_id)->toBe(123);

        Event::assertDispatched(N8nQueueJobStartedEvent::class);
        Event::assertDispatched(N8nQueueJobCompletedEvent::class);
    });

    it('marks job Failed on HTTP error', function() {
        Http::fake(['*/webhook*/*' => Http::response(['error' => 'Internal error'], 500)]);
        Event::fake([N8nQueueJobFailedEvent::class]);

        $job = N8nQueueJobFactory::new()->create([
            'workflow_id' => $this->workflow->id,
            'queue_name'  => 'default',
            'status'      => QueueJobStatus::Pending->value,
        ]);

        $this->worker->run('default', sleep: 0, maxJobs: 1);

        $fresh = $job->fresh();
        expect($fresh->status)->toBeIn([QueueJobStatus::Failed, QueueJobStatus::Dead, QueueJobStatus::Pending])
            ->and(N8nQueueFailure::where('job_id', $job->id)->count())->toBeGreaterThan(0);

        Event::assertDispatched(N8nQueueJobFailedEvent::class);
    });

    it('does not process delayed jobs', function() {
        Http::fake(['*/webhook*/*' => Http::response([], 200)]);

        $delayed = N8nQueueJobFactory::new()->delayed(300)->create([
            'workflow_id' => $this->workflow->id,
            'queue_name'  => 'default',
        ]);

        // maxTime: 2 stops the loop before the 300-second delay elapses
        $this->worker->run('default', sleep: 0, maxJobs: 1, maxTime: 2);

        expect($delayed->fresh()->status)->toBe(QueueJobStatus::Pending);
    });

    it('stops after maxTime seconds', function() {
        $start = time();
        $this->worker->run('default', sleep: 0, maxJobs: 0, maxTime: 1);
        $elapsed = time() - $start;

        expect($elapsed)->toBeLessThan(5);
    });
});

// ── Rate limiting ─────────────────────────────────────────────────────────────

describe('QueueWorker: rate limiting', function() {
    it('releases job back to pending when workflow rate limit exceeded', function() {
        Http::fake(['*/webhook*/*' => Http::response(['executionId' => 123], 200)]);

        // Set per-workflow limit to 1 req/min
        $this->workflow->update(['rate_limit' => 1]);
        $wf = $this->workflow->fresh();

        app(OutboundRateLimiter::class)->reset($wf);

        // Exhaust the bucket with a direct check (simulates one job already processed)
        app(OutboundRateLimiter::class)->check($wf);

        $job = N8nQueueJobFactory::new()->create([
            'workflow_id' => $wf->id,
            'queue_name'  => 'default',
            'status'      => QueueJobStatus::Pending->value,
        ]);

        $this->worker->run('default', sleep: 0, maxJobs: 1);

        // Job should be back to Pending with a future available_at
        $fresh = $job->fresh();
        expect($fresh->status)->toBe(QueueJobStatus::Pending)
            ->and($fresh->worker_id)->toBeNull()
            ->and($fresh->available_at?->isFuture())->toBeTrue();
    });

    it('processes job normally when rate limit allows', function() {
        Http::fake(['*/webhook*/*' => Http::response(['executionId' => 123], 200)]);
        Event::fake([N8nQueueJobCompletedEvent::class]);

        $this->workflow->update(['rate_limit' => 5]);

        $job = N8nQueueJobFactory::new()->create([
            'workflow_id' => $this->workflow->id,
            'queue_name'  => 'default',
            'status'      => QueueJobStatus::Pending->value,
        ]);

        $this->worker->run('default', sleep: 0, maxJobs: 1);

        expect($job->fresh()->status)->toBe(QueueJobStatus::Done);
        Event::assertDispatched(N8nQueueJobCompletedEvent::class);
    });
});
