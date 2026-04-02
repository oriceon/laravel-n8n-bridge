<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\DeliveryStatus;

covers(DeliveryStatus::class);

describe('DeliveryStatus', function() {
    it('identifies terminal statuses correctly', function(DeliveryStatus $status, bool $expected) {
        expect($status->isTerminal())->toBe($expected);
    })->with([
        'done is terminal'        => [DeliveryStatus::Done,       true],
        'dlq is terminal'         => [DeliveryStatus::Dlq,        true],
        'skipped is terminal'     => [DeliveryStatus::Skipped,    true],
        'failed not terminal'     => [DeliveryStatus::Failed,     false],
        'retrying not terminal'   => [DeliveryStatus::Retrying,   false],
        'received not terminal'   => [DeliveryStatus::Received,   false],
        'processing not terminal' => [DeliveryStatus::Processing, false],
    ]);

    it('identifies retryable statuses correctly', function(DeliveryStatus $status, bool $expected) {
        expect($status->isRetryable())->toBe($expected);
    })->with([
        'failed is retryable'    => [DeliveryStatus::Failed,   true],
        'retrying is retryable'  => [DeliveryStatus::Retrying, true],
        'done not retryable'     => [DeliveryStatus::Done,     false],
        'dlq not retryable'      => [DeliveryStatus::Dlq,      false],
        'received not retryable' => [DeliveryStatus::Received, false],
    ]);

    it('has a color for every case', function(DeliveryStatus $status) {
        expect($status->color())->toBeString()->not->toBeEmpty();
    })->with(DeliveryStatus::cases());

    it('has a label for every case', function(DeliveryStatus $status) {
        expect($status->label())->toBeString()->not->toBeEmpty();
    })->with(DeliveryStatus::cases());

    it('returns correct terminal cases list', function() {
        $terminal = DeliveryStatus::terminal();
        expect($terminal)
            ->toContain(DeliveryStatus::Done)
            ->toContain(DeliveryStatus::Dlq)
            ->toContain(DeliveryStatus::Skipped)
            ->not->toContain(DeliveryStatus::Failed);
    });

    it('can be created from string value', function() {
        expect(DeliveryStatus::from('done'))->toBe(DeliveryStatus::Done)
            ->and(DeliveryStatus::from('dlq'))->toBe(DeliveryStatus::Dlq)
            ->and(DeliveryStatus::tryFrom('invalid'))->toBeNull();
    });

    it('identifies success and failure correctly', function() {
        expect(DeliveryStatus::Done->isSuccess())->toBeTrue()
            ->and(DeliveryStatus::Failed->isSuccess())->toBeFalse()
            ->and(DeliveryStatus::Failed->isFailure())->toBeTrue()
            ->and(DeliveryStatus::Dlq->isFailure())->toBeTrue()
            ->and(DeliveryStatus::Done->isFailure())->toBeFalse();
    });
});
