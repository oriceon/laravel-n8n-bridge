<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Database\Factories\N8nQueueJobFactory;
use Oriceon\N8nBridge\Database\Factories\N8nWorkflowFactory;
use Oriceon\N8nBridge\Enums\QueueJobPriority;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Models\N8nQueueBatch;
use Oriceon\N8nBridge\Models\N8nQueueJob;

covers(N8nQueueBatch::class);

beforeEach(function() {
    $this->workflow = N8nWorkflowFactory::new()->create();

    $this->batch = N8nQueueBatch::create([
        'name'     => 'Test Batch',
        'priority' => QueueJobPriority::Normal->value,
    ]);
});

// ── progressPercent() ─────────────────────────────────────────────────────────

describe('N8nQueueBatch::progressPercent()', function() {
    it('returns 0 when total_jobs is zero', function() {
        expect($this->batch->progressPercent())->toBe(0.0);
    });

    it('calculates progress from done + dead + cancelled', function() {
        $this->batch->update([
            'total_jobs'     => 10,
            'done_jobs'      => 5,
            'dead_jobs'      => 2,
            'cancelled_jobs' => 1,
        ]);

        // (5 + 2 + 1) / 10 * 100 = 80.0
        expect($this->batch->fresh()->progressPercent())->toBe(80.0);
    });
});

// ── successRate() ─────────────────────────────────────────────────────────────

describe('N8nQueueBatch::successRate()', function() {
    it('returns 0 when no processed jobs', function() {
        $this->batch->update(['total_jobs' => 5, 'done_jobs' => 0, 'dead_jobs' => 0]);
        expect($this->batch->fresh()->successRate())->toBe(0.0);
    });

    it('calculates done / (done + dead)', function() {
        $this->batch->update(['total_jobs' => 10, 'done_jobs' => 8, 'dead_jobs' => 2]);
        expect($this->batch->fresh()->successRate())->toBe(80.0);
    });
});

// ── isComplete() ─────────────────────────────────────────────────────────────

describe('N8nQueueBatch::isComplete()', function() {
    it('returns false when no jobs', function() {
        expect($this->batch->isComplete())->toBeFalse();
    });

    it('returns true when all jobs finished', function() {
        $this->batch->update(['total_jobs' => 3, 'done_jobs' => 2, 'dead_jobs' => 1]);
        expect($this->batch->fresh()->isComplete())->toBeTrue();
    });

    it('returns false when pending jobs remain', function() {
        $this->batch->update(['total_jobs' => 3, 'done_jobs' => 1]);
        expect($this->batch->fresh()->isComplete())->toBeFalse();
    });
});

// ── isPending() ───────────────────────────────────────────────────────────────

describe('N8nQueueBatch::isPending()', function() {
    it('returns true when pending jobs exist and not cancelled', function() {
        $this->batch->update(['pending_jobs' => 5, 'cancelled' => false]);
        expect($this->batch->fresh()->isPending())->toBeTrue();
    });

    it('returns false when batch is cancelled', function() {
        $this->batch->update(['pending_jobs' => 5, 'cancelled' => true]);
        expect($this->batch->fresh()->isPending())->toBeFalse();
    });

    it('returns false when no pending jobs', function() {
        $this->batch->update(['pending_jobs' => 0]);
        expect($this->batch->fresh()->isPending())->toBeFalse();
    });
});

// ── cancel() ─────────────────────────────────────────────────────────────────

describe('N8nQueueBatch::cancel()', function() {
    it('marks batch as cancelled and cancels pending jobs', function() {
        N8nQueueJobFactory::new()->count(3)->create([
            'workflow_id' => $this->workflow->id,
            'batch_id'    => $this->batch->id,
            'status'      => QueueJobStatus::Pending->value,
        ]);
        N8nQueueJobFactory::new()->done()->create([
            'workflow_id' => $this->workflow->id,
            'batch_id'    => $this->batch->id,
        ]);

        $this->batch->cancel();

        $cancelled = N8nQueueJob::where('batch_id', $this->batch->id)
            ->where('status', QueueJobStatus::Cancelled->value)
            ->count();

        expect($this->batch->fresh()->cancelled)->toBeTrue()
            ->and($cancelled)->toBe(3);
    });
});

// ── recalculate() ────────────────────────────────────────────────────────────

describe('N8nQueueBatch::recalculate()', function() {
    it('updates job counts from actual job records', function() {
        N8nQueueJobFactory::new()->count(2)->create(['workflow_id' => $this->workflow->id, 'batch_id' => $this->batch->id, 'status' => QueueJobStatus::Pending->value]);
        N8nQueueJobFactory::new()->done()->create(['workflow_id' => $this->workflow->id, 'batch_id' => $this->batch->id]);
        N8nQueueJobFactory::new()->dead()->create(['workflow_id' => $this->workflow->id, 'batch_id' => $this->batch->id]);

        $this->batch->recalculate();
        $fresh = $this->batch->fresh();

        expect($fresh->pending_jobs)->toBe(2)
            ->and($fresh->done_jobs)->toBe(1)
            ->and($fresh->dead_jobs)->toBe(1);
    });

    it('sets finished_at when batch becomes complete', function() {
        N8nQueueJobFactory::new()->done()->create(['workflow_id' => $this->workflow->id, 'batch_id' => $this->batch->id]);
        $this->batch->update(['total_jobs' => 1]);

        $this->batch->recalculate();

        expect($this->batch->fresh()->finished_at)->not->toBeNull();
    });
});
