<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Database\Factories\N8nQueueJobFactory;
use Oriceon\N8nBridge\Database\Factories\N8nWorkflowFactory;
use Oriceon\N8nBridge\Enums\QueueJobPriority;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Models\N8nQueueBatch;
use Oriceon\N8nBridge\Models\N8nQueueJob;
use Oriceon\N8nBridge\Queue\QueueManager;

covers(QueueManager::class);

beforeEach(function() {
    $this->manager  = app(QueueManager::class);
    $this->workflow = N8nWorkflowFactory::new()->create(['name' => 'test-workflow', 'is_active' => true]);
});

// ── dispatch() ────────────────────────────────────────────────────────────────

describe('QueueManager::dispatch()', function() {
    it('creates a pending job', function() {
        $job = $this->manager->dispatch($this->workflow, ['order_id' => 1]);

        expect($job)->toBeInstanceOf(N8nQueueJob::class)
            ->and($job->status)->toBe(QueueJobStatus::Pending)
            ->and($job->payload)->toBe(['order_id' => 1]);
    });

    it('accepts priority and delay', function() {
        $job = $this->manager->dispatch(
            $this->workflow,
            ['id' => 5],
            QueueJobPriority::High,
            delaySeconds: 60,
        );

        expect($job->priority)->toBe(QueueJobPriority::High)
            ->and($job->available_at)->not->toBeNull()
            ->and($job->available_at->isFuture())->toBeTrue();
    });

    it('handles idempotency key — returns existing job', function() {
        $first  = $this->manager->dispatch($this->workflow, [], idempotencyKey: 'unique-op-1');
        $second = $this->manager->dispatch($this->workflow, [], idempotencyKey: 'unique-op-1');

        expect($second->id)->toBe($first->id);
    });
});

// ── dispatchMany() ────────────────────────────────────────────────────────────

describe('QueueManager::dispatchMany()', function() {
    it('creates a batch with multiple jobs', function() {
        $payloads = [['id' => 1], ['id' => 2], ['id' => 3]];

        $batch = $this->manager->dispatchMany($this->workflow, $payloads, batchName: 'My batch');

        expect($batch)->toBeInstanceOf(N8nQueueBatch::class)
            ->and($batch->total_jobs)->toBe(3)
            ->and(N8nQueueJob::where('batch_id', $batch->id)->count())->toBe(3);
    });
});

// ── job() / batch() ───────────────────────────────────────────────────────────

describe('QueueManager::job() and batch()', function() {
    it('returns a job by id', function() {
        $job = N8nQueueJobFactory::new()->create(['workflow_id' => $this->workflow->id]);

        expect($this->manager->job($job->id)?->id)->toBe($job->id);
    });

    it('returns null for unknown job id', function() {
        expect($this->manager->job(99999))->toBeNull();
    });

    it('returns a batch by id', function() {
        $batch = N8nQueueBatch::create(['name' => 'test', 'priority' => QueueJobPriority::Normal->value]);

        expect($this->manager->batch($batch->id)?->id)->toBe($batch->id);
    });
});

// ── pendingCount() ────────────────────────────────────────────────────────────

describe('QueueManager::pendingCount()', function() {
    it('counts pending jobs on the default queue', function() {
        N8nQueueJobFactory::new()->count(3)->create([
            'workflow_id' => $this->workflow->id,
            'status'      => QueueJobStatus::Pending->value,
            'queue_name'  => 'default',
        ]);

        expect($this->manager->pendingCount('default'))->toBe(3);
    });

    it('counts only on the specified queue', function() {
        N8nQueueJobFactory::new()->create(['workflow_id' => $this->workflow->id, 'queue_name' => 'default']);
        N8nQueueJobFactory::new()->create(['workflow_id' => $this->workflow->id, 'queue_name' => 'high']);

        expect($this->manager->pendingCount('high'))->toBe(1)
            ->and($this->manager->pendingCount('default'))->toBe(1);
    });
});

// ── deadLetters() ─────────────────────────────────────────────────────────────

describe('QueueManager::deadLetters()', function() {
    it('returns dead jobs with workflow relation', function() {
        N8nQueueJobFactory::new()->dead()->create([
            'workflow_id' => $this->workflow->id,
            'queue_name'  => 'default',
        ]);

        $dead = $this->manager->deadLetters('default');

        expect($dead)->toHaveCount(1)
            ->and($dead->first()->workflow)->not->toBeNull();
    });

    it('does not include non-dead jobs', function() {
        N8nQueueJobFactory::new()->create(['workflow_id' => $this->workflow->id, 'queue_name' => 'default']);

        expect($this->manager->deadLetters('default'))->toBeEmpty();
    });
});

// ── stats() ───────────────────────────────────────────────────────────────────

describe('QueueManager::stats()', function() {
    it('returns correct structure with required keys', function() {
        $stats = $this->manager->stats('default');

        expect($stats)->toHaveKeys([
            'pending', 'running', 'done_today', 'failed_today',
            'dead_total', 'avg_duration_ms', 'success_rate',
            'by_priority', 'by_reason',
        ]);
    });

    it('counts pending jobs', function() {
        N8nQueueJobFactory::new()->count(2)->create([
            'workflow_id' => $this->workflow->id,
            'queue_name'  => 'default',
            'status'      => QueueJobStatus::Pending->value,
        ]);

        expect($this->manager->stats('default')['pending'])->toBe(2);
    });

    it('counts dead jobs', function() {
        N8nQueueJobFactory::new()->dead()->create(['workflow_id' => $this->workflow->id, 'queue_name' => 'default']);

        expect($this->manager->stats('default')['dead_total'])->toBe(1);
    });

    it('returns null success_rate when no processed jobs', function() {
        expect($this->manager->stats('default')['success_rate'])->toBeNull();
    });
});
