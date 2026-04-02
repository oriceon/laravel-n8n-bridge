<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\QueueJobStatus;

covers(QueueJobStatus::class);

describe('QueueJobStatus', function() {
    it('identifies terminal statuses correctly', function(QueueJobStatus $status, bool $expected) {
        expect($status->isTerminal())->toBe($expected);
    })->with([
        'done is terminal'      => [QueueJobStatus::Done,      true],
        'dead is terminal'      => [QueueJobStatus::Dead,      true],
        'cancelled is terminal' => [QueueJobStatus::Cancelled, true],
        'pending not terminal'  => [QueueJobStatus::Pending,   false],
        'claimed not terminal'  => [QueueJobStatus::Claimed,   false],
        'running not terminal'  => [QueueJobStatus::Running,   false],
        'failed not terminal'   => [QueueJobStatus::Failed,    false],
    ]);

    it('identifies active statuses correctly', function(QueueJobStatus $status, bool $expected) {
        expect($status->isActive())->toBe($expected);
    })->with([
        'claimed is active'  => [QueueJobStatus::Claimed, true],
        'running is active'  => [QueueJobStatus::Running, true],
        'pending not active' => [QueueJobStatus::Pending, false],
        'done not active'    => [QueueJobStatus::Done,    false],
        'dead not active'    => [QueueJobStatus::Dead,    false],
        'failed not active'  => [QueueJobStatus::Failed,  false],
    ]);

    it('only failed can retry', function(QueueJobStatus $status, bool $expected) {
        expect($status->canRetry())->toBe($expected);
    })->with([
        'failed can retry'     => [QueueJobStatus::Failed,    true],
        'dead cannot retry'    => [QueueJobStatus::Dead,      false],
        'done cannot retry'    => [QueueJobStatus::Done,      false],
        'pending cannot retry' => [QueueJobStatus::Pending,   false],
    ]);

    it('has a label for every case', function(QueueJobStatus $status) {
        expect($status->label())->toBeString()->not->toBeEmpty();
    })->with(QueueJobStatus::cases());

    it('has a color for every case', function(QueueJobStatus $status) {
        expect($status->color())->toBeString()->not->toBeEmpty();
    })->with(QueueJobStatus::cases());

    it('can round-trip through string value', function(QueueJobStatus $status) {
        expect(QueueJobStatus::from($status->value))->toBe($status);
    })->with(QueueJobStatus::cases());

    it('tryFrom returns null for unknown value', function() {
        expect(QueueJobStatus::tryFrom('nonexistent'))->toBeNull();
    });
});
