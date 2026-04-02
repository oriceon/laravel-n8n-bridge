<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Oriceon\N8nBridge\Database\Factories\N8nWorkflowFactory;
use Oriceon\N8nBridge\Enums\QueueJobPriority;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Models\N8nQueueBatch;
use Oriceon\N8nBridge\Models\N8nQueueJob;
use Oriceon\N8nBridge\Models\N8nWorkflow;
use Oriceon\N8nBridge\Queue\QueueDispatcher;

covers(QueueDispatcher::class);

beforeEach(function() {
    $this->workflow = N8nWorkflowFactory::new()->create(['name' => 'invoice-reminder', 'is_active' => true]);
});

// ── Single job dispatch ───────────────────────────────────────────────────────

describe('QueueDispatcher single job', function() {
    it('dispatches a pending job', function() {
        $job = QueueDispatcher::workflow($this->workflow)
            ->payload(['invoice_id' => 42])
            ->dispatch();

        expect($job)->toBeInstanceOf(N8nQueueJob::class)
            ->and($job->status)->toBe(QueueJobStatus::Pending)
            ->and($job->payload)->toBe(['invoice_id' => 42])
            ->and($job->workflow_id)->toBe($this->workflow->id);
    });

    it('resolves workflow by name string', function() {
        $job = QueueDispatcher::workflow('invoice-reminder')
            ->dispatch();

        expect($job->workflow_id)->toBe($this->workflow->id);
    });

    it('throws when workflow name not found', function() {
        expect(fn(): N8nQueueJob => QueueDispatcher::workflow('nonexistent')->dispatch())
            ->toThrow(ModelNotFoundException::class);
    });

    it('throws when no workflow set', function() {
        expect(fn(): N8nQueueJob => QueueDispatcher::batch('orphan')->dispatch())
            ->toThrow(\LogicException::class);
    });

    it('sets priority correctly', function() {
        $job = QueueDispatcher::workflow($this->workflow)
            ->priority(QueueJobPriority::High)
            ->dispatch();

        expect($job->priority)->toBe(QueueJobPriority::High);
    });

    it('priority shortcuts work', function() {
        $critical = QueueDispatcher::workflow($this->workflow)->critical()->dispatch();
        $high     = QueueDispatcher::workflow($this->workflow)->high()->dispatch();
        $normal   = QueueDispatcher::workflow($this->workflow)->normal()->dispatch();
        $low      = QueueDispatcher::workflow($this->workflow)->low()->dispatch();
        $bulk     = QueueDispatcher::workflow($this->workflow)->bulk()->dispatch();

        expect($critical->priority)->toBe(QueueJobPriority::Critical)
            ->and($high->priority)->toBe(QueueJobPriority::High)
            ->and($normal->priority)->toBe(QueueJobPriority::Normal)
            ->and($low->priority)->toBe(QueueJobPriority::Low)
            ->and($bulk->priority)->toBe(QueueJobPriority::Bulk);
    });

    it('sets delay in seconds', function() {
        $job = QueueDispatcher::workflow($this->workflow)
            ->delay(seconds: 300)
            ->dispatch();

        expect($job->available_at)->not->toBeNull()
            ->and($job->available_at->isFuture())->toBeTrue();
    });

    it('combines delay in minutes and hours', function() {
        $job = QueueDispatcher::workflow($this->workflow)
            ->delay(minutes: 1, hours: 1) // 3660 seconds
            ->dispatch();

        expect($job->available_at->diffInSeconds(now(), absolute: true))->toBeGreaterThan(3600);
    });

    it('availableAt() sets delay from CarbonInterface', function() {
        $at  = now()->addMinutes(10);
        $job = QueueDispatcher::workflow($this->workflow)
            ->availableAt($at)
            ->dispatch();

        expect($job->available_at)->not->toBeNull()
            ->and($job->available_at->isFuture())->toBeTrue();
    });

    it('sets queue name', function() {
        $job = QueueDispatcher::workflow($this->workflow)
            ->onQueue('critical')
            ->dispatch();

        expect($job->queue_name)->toBe('critical');
    });

    it('sets maxAttempts and timeout', function() {
        $job = QueueDispatcher::workflow($this->workflow)
            ->maxAttempts(10)
            ->timeout(300)
            ->dispatch();

        expect($job->max_attempts)->toBe(10)
            ->and($job->timeout_seconds)->toBe(300);
    });

    it('stores context metadata', function() {
        $job = QueueDispatcher::workflow($this->workflow)
            ->context(['triggered_by' => 'InvoicePaidListener'])
            ->dispatch();

        expect($job->context)->toBe(['triggered_by' => 'InvoicePaidListener']);
    });

    it('sets n8n instance', function() {
        $job = QueueDispatcher::workflow($this->workflow)
            ->instance('production')
            ->dispatch();

        expect($job->n8n_instance)->toBe('production');
    });
});

// ── Idempotency ───────────────────────────────────────────────────────────────

describe('QueueDispatcher idempotency', function() {
    it('returns existing job for same idempotency key', function() {
        $first  = QueueDispatcher::workflow($this->workflow)->idempotent('inv-42')->dispatch();
        $second = QueueDispatcher::workflow($this->workflow)->idempotent('inv-42')->dispatch();

        expect($second->id)->toBe($first->id)
            ->and(N8nQueueJob::where('idempotency_key', 'inv-42')->count())->toBe(1);
    });

    it('creates new job when previous is Dead', function() {
        $dead = QueueDispatcher::workflow($this->workflow)->idempotent('inv-dead')->dispatch();
        $dead->update(['status' => QueueJobStatus::Dead->value]);

        $new = QueueDispatcher::workflow($this->workflow)->idempotent('inv-dead')->dispatch();

        // Old job's key is cleared so the unique constraint is freed up; only the new job holds the key
        expect($new->id)->not->toBe($dead->id)
            ->and(N8nQueueJob::where('idempotency_key', 'inv-dead')->count())->toBe(1)
            ->and($new->idempotency_key)->toBe('inv-dead')
            ->and($dead->fresh()->idempotency_key)->toBeNull();
    });

    it('creates new job when previous is Cancelled', function() {
        $cancelled = QueueDispatcher::workflow($this->workflow)->idempotent('inv-can')->dispatch();
        $cancelled->update(['status' => QueueJobStatus::Cancelled->value]);

        $new = QueueDispatcher::workflow($this->workflow)->idempotent('inv-can')->dispatch();
        expect($new->id)->not->toBe($cancelled->id);
    });
});

// ── Bulk batch ────────────────────────────────────────────────────────────────

describe('QueueDispatcher::dispatchMany()', function() {
    it('creates a batch and all jobs', function() {
        $payloads = [
            ['invoice_id' => 1],
            ['invoice_id' => 2],
            ['invoice_id' => 3],
        ];

        $batch = QueueDispatcher::batch('Test Batch')
            ->forWorkflow($this->workflow)
            ->dispatchMany($payloads);

        expect($batch)->toBeInstanceOf(N8nQueueBatch::class)
            ->and($batch->total_jobs)->toBe(3)
            ->and(N8nQueueJob::where('batch_id', $batch->id)->count())->toBe(3);
    });

    it('batch name appears in the batch record', function() {
        $batch = QueueDispatcher::batch('Monthly reminders', 'All overdue invoices')
            ->forWorkflow($this->workflow)
            ->dispatchMany([['id' => 1]]);

        expect($batch->name)->toBe('Monthly reminders')
            ->and($batch->description)->toBe('All overdue invoices');
    });

    it('all batch jobs have Pending status', function() {
        $batch = QueueDispatcher::batch('Pending batch')
            ->forWorkflow($this->workflow)
            ->dispatchMany([['id' => 1], ['id' => 2]]);

        $statuses = N8nQueueJob::where('batch_id', $batch->id)->pluck('status')->map->value->toArray();
        expect($statuses)->each->toBe(QueueJobStatus::Pending->value);
    });

    it('dispatchMany sets started_at on batch', function() {
        $batch = QueueDispatcher::batch('b')
            ->forWorkflow($this->workflow)
            ->dispatchMany([['x' => 1]]);

        expect($batch->started_at)->not->toBeNull();
    });

    it('respects priority on batch jobs', function() {
        $batch = QueueDispatcher::batch('bulk')
            ->forWorkflow($this->workflow)
            ->priority(QueueJobPriority::Bulk)
            ->dispatchMany([['id' => 1]]);

        $job = N8nQueueJob::where('batch_id', $batch->id)->first();
        expect($job->priority)->toBe(QueueJobPriority::Bulk);
    });

    it('throws LogicException when no workflow set', function() {
        expect(fn(): N8nQueueBatch => QueueDispatcher::batch('no-workflow')->dispatchMany([['id' => 1]]))
            ->toThrow(\LogicException::class);
    });
});

// ── dispatchFromQuery() ───────────────────────────────────────────────────────

describe('QueueDispatcher::dispatchFromQuery()', function() {
    it('dispatches jobs from an Eloquent query', function() {
        // Create 3 workflows and dispatch them
        N8nWorkflow::factory()->count(3)->create();

        $batch = QueueDispatcher::batch('Query batch')
            ->forWorkflow($this->workflow)
            ->dispatchFromQuery(
                N8nWorkflow::query(),
                fn(N8nWorkflow $wf): array => ['workflow_name' => $wf->name],
            );

        $totalWorkflows = N8nWorkflow::count();

        expect($batch->total_jobs)->toBe($totalWorkflows)
            ->and(N8nQueueJob::where('batch_id', $batch->id)->count())->toBe($totalWorkflows);
    });
});
