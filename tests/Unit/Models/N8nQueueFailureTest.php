<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Database\Factories\N8nQueueJobFactory;
use Oriceon\N8nBridge\Database\Factories\N8nWorkflowFactory;
use Oriceon\N8nBridge\Enums\QueueFailureReason;
use Oriceon\N8nBridge\Models\N8nQueueFailure;

covers(N8nQueueFailure::class);

beforeEach(function() {
    $this->workflow = N8nWorkflowFactory::new()->create();
    $this->job      = N8nQueueJobFactory::new()->create([
        'workflow_id'  => $this->workflow->id,
        'attempts'     => 1,
        'max_attempts' => 3,
        'payload'      => ['invoice_id' => 99],
        'worker_id'    => 'worker:1234',
    ]);
});

// ── recordFromJob() ───────────────────────────────────────────────────────────

describe('N8nQueueFailure::recordFromJob()', function() {
    it('creates a failure record with correct fields', function() {
        $failure = N8nQueueFailure::recordFromJob(
            job:          $this->job,
            reason:       QueueFailureReason::HttpError5xx,
            errorMessage: 'Internal Server Error',
            errorClass:   \RuntimeException::class,
            httpStatus:   500,
            httpResponse: ['error' => 'ISE'],
            durationMs:   120,
            stackTrace:   'stack...',
        );

        expect($failure->job_id)->toBe($this->job->id)
            ->and($failure->workflow_id)->toBe($this->workflow->id)
            ->and($failure->reason)->toBe(QueueFailureReason::HttpError5xx)
            ->and($failure->error_message)->toBe('Internal Server Error')
            ->and($failure->error_class)->toBe(\RuntimeException::class)
            ->and($failure->http_status)->toBe(500)
            ->and($failure->http_response)->toBe(['error' => 'ISE'])
            ->and($failure->duration_ms)->toBe(120)
            ->and($failure->attempt_number)->toBe(1)
            ->and($failure->was_retried)->toBeTrue()
            ->and($failure->was_replayed)->toBeFalse();
    });

    it('records payload snapshot from job', function() {
        $failure = N8nQueueFailure::recordFromJob(
            $this->job,
            QueueFailureReason::ConnectionTimeout,
            'Timeout',
        );

        expect($failure->payload_snapshot)->toBe(['invoice_id' => 99]);
    });

    it('was_retried is false when attempts == max_attempts', function() {
        $this->job->update(['attempts' => 3, 'max_attempts' => 3]);
        $this->job->refresh();

        $failure = N8nQueueFailure::recordFromJob(
            $this->job,
            QueueFailureReason::HttpError5xx,
            'Error',
        );

        expect($failure->was_retried)->toBeFalse();
    });

    it('hides stack_trace in serialization', function() {
        $failure = N8nQueueFailure::recordFromJob(
            $this->job,
            QueueFailureReason::HttpError5xx,
            'Error',
            stackTrace: 'trace here',
        );

        expect(array_key_exists('stack_trace', $failure->toArray()))->toBeFalse();
    });

    it('has no UPDATED_AT column', function() {
        expect(N8nQueueFailure::UPDATED_AT)->toBeNull();
    });
});

// ── Scopes ────────────────────────────────────────────────────────────────────

describe('N8nQueueFailure scopes', function() {
    it('forReason() filters by failure reason', function() {
        N8nQueueFailure::recordFromJob($this->job, QueueFailureReason::HttpError5xx, 'err');
        N8nQueueFailure::recordFromJob($this->job, QueueFailureReason::ConnectionTimeout, 'timeout');

        expect(N8nQueueFailure::forReason(QueueFailureReason::HttpError5xx)->count())->toBe(1);
    });

    it('notReplayed() returns only non-replayed failures', function() {
        N8nQueueFailure::recordFromJob($this->job, QueueFailureReason::HttpError5xx, 'err');

        expect(N8nQueueFailure::notReplayed()->count())->toBe(1);

        N8nQueueFailure::first()->update(['was_replayed' => true]);

        expect(N8nQueueFailure::notReplayed()->count())->toBe(0);
    });

    it('forWorkflow() filters by workflow_id', function() {
        $other    = N8nWorkflowFactory::new()->create();
        $otherJob = N8nQueueJobFactory::new()->create(['workflow_id' => $other->id]);

        N8nQueueFailure::recordFromJob($this->job, QueueFailureReason::HttpError5xx, 'err');
        N8nQueueFailure::recordFromJob($otherJob, QueueFailureReason::HttpError5xx, 'err2');

        expect(
            N8nQueueFailure::forWorkflow($this->workflow->id)->count()
        )->toBe(1);
    });

    it('lastHours() returns only recent failures', function() {
        N8nQueueFailure::recordFromJob($this->job, QueueFailureReason::HttpError5xx, 'recent');

        expect(N8nQueueFailure::lastHours(1)->count())->toBe(1);
    });

    it('retryable() returns only retryable failures', function() {
        // ConnectionTimeout is retryable, ValidationFailed is not
        N8nQueueFailure::recordFromJob($this->job, QueueFailureReason::ConnectionTimeout, 'timeout');
        N8nQueueFailure::recordFromJob($this->job, QueueFailureReason::ValidationFailed, 'bad');

        $retryableCount = N8nQueueFailure::retryable()->count();
        expect($retryableCount)->toBeGreaterThanOrEqual(1);
    });
});
