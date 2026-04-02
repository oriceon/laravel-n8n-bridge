<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Database\Factories\N8nQueueJobFactory;
use Oriceon\N8nBridge\Database\Factories\N8nWorkflowFactory;
use Oriceon\N8nBridge\Enums\QueueFailureReason;
use Oriceon\N8nBridge\Enums\QueueJobPriority;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Models\N8nQueueJob;

covers(N8nQueueJob::class);

beforeEach(function() {
    $this->workflow = N8nWorkflowFactory::new()->create();
});

// ── State transitions ─────────────────────────────────────────────────────────

describe('N8nQueueJob::claim()', function() {
    it('transitions to Claimed with worker info', function() {
        $job = N8nQueueJobFactory::new()->create(['workflow_id' => $this->workflow->id]);

        $result = $job->claim('worker-123', 120);

        expect($result)->toBeTrue()
            ->and($job->fresh()->status)->toBe(QueueJobStatus::Claimed)
            ->and($job->fresh()->worker_id)->toBe('worker-123')
            ->and($job->fresh()->reserved_until)->not->toBeNull()
            ->and($job->fresh()->attempts)->toBe(1);
    });

    it('returns false if already claimed by another worker', function() {
        $job = N8nQueueJobFactory::new()->create([
            'workflow_id' => $this->workflow->id,
            'status'      => QueueJobStatus::Claimed->value,
        ]);

        expect($job->claim('another-worker', 60))->toBeFalse();
    });
});

describe('N8nQueueJob::markRunning()', function() {
    it('transitions to Running', function() {
        $job = N8nQueueJobFactory::new()->create([
            'workflow_id' => $this->workflow->id,
            'status'      => QueueJobStatus::Claimed->value,
        ]);

        $job->markRunning();

        expect($job->fresh()->status)->toBe(QueueJobStatus::Running);
    });
});

describe('N8nQueueJob::markDone()', function() {
    it('sets Done status with duration and execution id', function() {
        $job = N8nQueueJobFactory::new()->create([
            'workflow_id' => $this->workflow->id,
            'status'      => QueueJobStatus::Running->value,
            'started_at'  => now()->subSeconds(2),
        ]);

        $job->markDone(['execution_id' => 123]);

        expect($job->fresh()->status)->toBe(QueueJobStatus::Done)
            ->and($job->fresh()->n8n_execution_id)->toBe(123)
            ->and($job->fresh()->finished_at)->not->toBeNull()
            ->and($job->fresh()->duration_ms)->toBeGreaterThan(0);
    });

    it('stores n8n response payload', function() {
        $job = N8nQueueJobFactory::new()->create(['workflow_id' => $this->workflow->id, 'started_at' => now()]);
        $job->markDone(['key1' => 'value1', 'key2' => 123]);

        expect($job->fresh()->n8n_response)->toBe(['key1' => 'value1', 'key2' => 123]);
    });
});

describe('N8nQueueJob::markFailed()', function() {
    it('transitions to Failed and records error info', function() {
        $job = N8nQueueJobFactory::new()->create([
            'workflow_id'  => $this->workflow->id,
            'status'       => QueueJobStatus::Running->value,
            'attempts'     => 1,
            'max_attempts' => 3,
        ]);

        $job->markFailed(QueueFailureReason::HttpError5xx, 'Service Unavailable', RuntimeException::class, 60);

        $fresh = $job->fresh();
        expect($fresh->status)->toBeIn([QueueJobStatus::Failed, QueueJobStatus::Pending])
            ->and($fresh->last_error_message)->toBe('Service Unavailable')
            ->and($fresh->last_failure_reason)->toBe(QueueFailureReason::HttpError5xx);
    });

    it('transitions to Dead when attempts exhausted', function() {
        $job = N8nQueueJobFactory::new()->create([
            'workflow_id'  => $this->workflow->id,
            'status'       => QueueJobStatus::Running->value,
            'attempts'     => 3,
            'max_attempts' => 3,
        ]);

        $job->markFailed(QueueFailureReason::HttpError5xx, 'Too many errors', RuntimeException::class);

        expect($job->fresh()->status)->toBe(QueueJobStatus::Dead);
    });
});

describe('N8nQueueJob::cancel()', function() {
    it('transitions to Cancelled and clears worker', function() {
        $job = N8nQueueJobFactory::new()->create([
            'workflow_id'    => $this->workflow->id,
            'status'         => QueueJobStatus::Claimed->value,
            'worker_id'      => 'some-worker',
            'reserved_until' => now()->addMinutes(2),
        ]);

        $job->cancel('Testing cancel');

        expect($job->fresh()->status)->toBe(QueueJobStatus::Cancelled)
            ->and($job->fresh()->worker_id)->toBeNull()
            ->and($job->fresh()->reserved_until)->toBeNull()
            ->and($job->fresh()->last_error_message)->toBe('Testing cancel');
    });
});

describe('N8nQueueJob::reschedule()', function() {
    it('resets to Pending with new available_at', function() {
        $job = N8nQueueJobFactory::new()->create([
            'workflow_id' => $this->workflow->id,
            'status'      => QueueJobStatus::Failed->value,
        ]);

        $at = now()->addHours(2);
        $job->reschedule($at);

        expect($job->fresh()->status)->toBe(QueueJobStatus::Pending)
            ->and($job->fresh()->available_at->isFuture())->toBeTrue();
    });
});

// ── Helpers ───────────────────────────────────────────────────────────────────

describe('N8nQueueJob helpers', function() {
    it('isExhausted() returns true when attempts >= max_attempts', function() {
        $job = N8nQueueJobFactory::new()->create(['workflow_id' => $this->workflow->id, 'attempts' => 3, 'max_attempts' => 3]);
        expect($job->isExhausted())->toBeTrue();
    });

    it('isExhausted() returns false when attempts < max_attempts', function() {
        $job = N8nQueueJobFactory::new()->create(['workflow_id' => $this->workflow->id, 'attempts' => 1, 'max_attempts' => 3]);
        expect($job->isExhausted())->toBeFalse();
    });

    it('isDue() returns true when available_at is null', function() {
        $job = N8nQueueJobFactory::new()->create(['workflow_id' => $this->workflow->id, 'available_at' => null]);
        expect($job->isDue())->toBeTrue();
    });

    it('isDue() returns true when available_at is in the past', function() {
        $job = N8nQueueJobFactory::new()->create(['workflow_id' => $this->workflow->id, 'available_at' => now()->subMinute()]);
        expect($job->isDue())->toBeTrue();
    });

    it('isDue() returns false when delayed', function() {
        $job = N8nQueueJobFactory::new()->delayed(300)->create(['workflow_id' => $this->workflow->id]);
        expect($job->isDue())->toBeFalse();
    });
});

// ── Scopes ────────────────────────────────────────────────────────────────────

describe('N8nQueueJob scopes', function() {
    it('available() returns pending jobs with past or null available_at', function() {
        N8nQueueJobFactory::new()->create(['workflow_id' => $this->workflow->id, 'status' => QueueJobStatus::Pending->value, 'available_at' => null]);
        N8nQueueJobFactory::new()->create(['workflow_id' => $this->workflow->id, 'status' => QueueJobStatus::Pending->value, 'available_at' => now()->subMinute()]);
        N8nQueueJobFactory::new()->delayed(60)->create(['workflow_id' => $this->workflow->id]);

        expect(N8nQueueJob::available()->count())->toBe(2);
    });

    it('deadLetters() returns only Dead jobs', function() {
        N8nQueueJobFactory::new()->dead()->create(['workflow_id' => $this->workflow->id]);
        N8nQueueJobFactory::new()->failed()->create(['workflow_id' => $this->workflow->id]);

        expect(N8nQueueJob::deadLetters()->count())->toBe(1);
    });

    it('active() returns Claimed and Running', function() {
        N8nQueueJobFactory::new()->create(['workflow_id' => $this->workflow->id, 'status' => QueueJobStatus::Claimed->value]);
        N8nQueueJobFactory::new()->create(['workflow_id' => $this->workflow->id, 'status' => QueueJobStatus::Running->value]);
        N8nQueueJobFactory::new()->create(['workflow_id' => $this->workflow->id, 'status' => QueueJobStatus::Pending->value]);

        expect(N8nQueueJob::active()->count())->toBe(2);
    });

    it('stuck() returns jobs with expired reserved_until', function() {
        N8nQueueJobFactory::new()->create([
            'workflow_id'    => $this->workflow->id,
            'status'         => QueueJobStatus::Running->value,
            'reserved_until' => now()->subMinutes(20),
        ]);
        N8nQueueJobFactory::new()->create([
            'workflow_id'    => $this->workflow->id,
            'status'         => QueueJobStatus::Running->value,
            'reserved_until' => now()->addMinutes(5),
        ]);

        expect(N8nQueueJob::stuck(10)->count())->toBe(1);
    });

    it('byPriority() filters by priority', function() {
        N8nQueueJobFactory::new()->critical()->create(['workflow_id' => $this->workflow->id]);
        N8nQueueJobFactory::new()->create(['workflow_id' => $this->workflow->id]);

        expect(N8nQueueJob::byPriority(QueueJobPriority::Critical)->count())->toBe(1);
    });

    it('forQueue() filters by queue name', function() {
        N8nQueueJobFactory::new()->create(['workflow_id' => $this->workflow->id, 'queue_name' => 'default']);
        N8nQueueJobFactory::new()->bulk()->create(['workflow_id' => $this->workflow->id]);

        expect(N8nQueueJob::forQueue('bulk')->count())->toBe(1);
    });
});
