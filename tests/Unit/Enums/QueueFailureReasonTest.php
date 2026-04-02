<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\QueueFailureReason;

covers(QueueFailureReason::class);

describe('QueueFailureReason', function() {
    describe('isRetryable', function() {
        it('marks network/server errors as retryable', function(QueueFailureReason $reason) {
            expect($reason->isRetryable())->toBeTrue();
        })->with([
            [QueueFailureReason::ConnectionTimeout],
            [QueueFailureReason::HttpError5xx],
            [QueueFailureReason::RateLimit],
            [QueueFailureReason::CircuitBreakerOpen],
            [QueueFailureReason::WorkerTimeout],
        ]);

        it('marks client/permanent errors as non-retryable', function(QueueFailureReason $reason) {
            expect($reason->isRetryable())->toBeFalse();
        })->with([
            [QueueFailureReason::HttpError4xx],
            [QueueFailureReason::PayloadTooLarge],
            [QueueFailureReason::WorkflowNotFound],
            [QueueFailureReason::ValidationFailed],
            [QueueFailureReason::UnknownException],
        ]);
    });

    describe('suggestedDelaySeconds', function() {
        it('returns a positive delay for every case', function(QueueFailureReason $reason) {
            expect($reason->suggestedDelaySeconds())->toBeGreaterThan(0);
        })->with(QueueFailureReason::cases());

        it('RateLimit has a longer delay than ConnectionTimeout', function() {
            expect(QueueFailureReason::RateLimit->suggestedDelaySeconds())
                ->toBeGreaterThan(QueueFailureReason::ConnectionTimeout->suggestedDelaySeconds());
        });
    });

    it('has a label for every case', function(QueueFailureReason $reason) {
        expect($reason->label())->toBeString()->not->toBeEmpty();
    })->with(QueueFailureReason::cases());

    describe('fromHttpStatus', function() {
        it('maps 404 to WorkflowNotFound', function() {
            expect(QueueFailureReason::fromHttpStatus(404))->toBe(QueueFailureReason::WorkflowNotFound);
        });

        it('maps 413 to PayloadTooLarge', function() {
            expect(QueueFailureReason::fromHttpStatus(413))->toBe(QueueFailureReason::PayloadTooLarge);
        });

        it('maps 429 to RateLimit', function() {
            expect(QueueFailureReason::fromHttpStatus(429))->toBe(QueueFailureReason::RateLimit);
        });

        it('maps 4xx range to HttpError4xx', function() {
            expect(QueueFailureReason::fromHttpStatus(400))->toBe(QueueFailureReason::HttpError4xx)
                ->and(QueueFailureReason::fromHttpStatus(422))->toBe(QueueFailureReason::HttpError4xx);
        });

        it('maps 5xx range to HttpError5xx', function() {
            expect(QueueFailureReason::fromHttpStatus(500))->toBe(QueueFailureReason::HttpError5xx)
                ->and(QueueFailureReason::fromHttpStatus(503))->toBe(QueueFailureReason::HttpError5xx);
        });

        it('maps unknown status to UnknownException', function() {
            expect(QueueFailureReason::fromHttpStatus(0))->toBe(QueueFailureReason::UnknownException)
                ->and(QueueFailureReason::fromHttpStatus(200))->toBe(QueueFailureReason::UnknownException);
        });
    });

    describe('fromException', function() {
        it('detects timeout exceptions', function() {
            $e = new \RuntimeException('Connection timed out');
            expect(QueueFailureReason::fromException($e))->toBe(QueueFailureReason::ConnectionTimeout);
        });

        it('detects connection refused exceptions', function() {
            $e = new \RuntimeException('Connection refused');
            expect(QueueFailureReason::fromException($e))->toBe(QueueFailureReason::ConnectionTimeout);
        });

        it('detects rate limit exceptions', function() {
            $e = new \RuntimeException('Rate limit exceeded 429');
            expect(QueueFailureReason::fromException($e))->toBe(QueueFailureReason::RateLimit);
        });

        it('falls back to UnknownException for generic errors', function() {
            $e = new \RuntimeException('Something went wrong');
            expect(QueueFailureReason::fromException($e))->toBe(QueueFailureReason::UnknownException);
        });
    });

    it('can round-trip through string value', function(QueueFailureReason $reason) {
        expect(QueueFailureReason::from($reason->value))->toBe($reason);
    })->with(QueueFailureReason::cases());
});
