<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\CheckpointStatus;

covers(CheckpointStatus::class);

describe('CheckpointStatus', function() {
    it('identifies terminal statuses correctly', function(CheckpointStatus $status, bool $expected) {
        expect($status->isTerminal())->toBe($expected);
    })->with([
        'completed is terminal' => [CheckpointStatus::Completed, true],
        'failed is terminal'    => [CheckpointStatus::Failed,    true],
        'skipped is terminal'   => [CheckpointStatus::Skipped,   true],
        'running not terminal'  => [CheckpointStatus::Running,   false],
        'waiting not terminal'  => [CheckpointStatus::Waiting,   false],
    ]);

    it('has a color for every case', function(CheckpointStatus $status) {
        expect($status->color())->toBeString()->not->toBeEmpty();
    })->with(CheckpointStatus::cases());

    it('has an icon for every case', function(CheckpointStatus $status) {
        expect($status->icon())->toBeString()->not->toBeEmpty();
    })->with(CheckpointStatus::cases());

    it('has a label for every case', function(CheckpointStatus $status) {
        expect($status->label())->toBeString()->not->toBeEmpty();
    })->with(CheckpointStatus::cases());

    it('can round-trip through string value', function(CheckpointStatus $status) {
        expect(CheckpointStatus::from($status->value))->toBe($status);
    })->with(CheckpointStatus::cases());

    it('tryFrom returns null for unknown value', function() {
        expect(CheckpointStatus::tryFrom('unknown'))->toBeNull();
    });

    it('failed status has a visually distinct color from completed', function() {
        expect(CheckpointStatus::Failed->color())->not->toBe(CheckpointStatus::Completed->color());
    });
});
