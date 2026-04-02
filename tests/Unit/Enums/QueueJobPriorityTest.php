<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\QueueJobPriority;

covers(QueueJobPriority::class);

describe('QueueJobPriority', function() {
    it('has numeric values in the expected order (higher = more urgent)', function() {
        expect(QueueJobPriority::Critical->value)->toBeGreaterThan(QueueJobPriority::High->value)
            ->and(QueueJobPriority::High->value)->toBeGreaterThan(QueueJobPriority::Normal->value)
            ->and(QueueJobPriority::Normal->value)->toBeGreaterThan(QueueJobPriority::Low->value)
            ->and(QueueJobPriority::Low->value)->toBeGreaterThan(QueueJobPriority::Bulk->value);
    });

    it('Normal priority has value 50', function() {
        expect(QueueJobPriority::Normal->value)->toBe(50);
    });

    it('has a label for every case', function(QueueJobPriority $priority) {
        expect($priority->label())->toBeString()->not->toBeEmpty();
    })->with(QueueJobPriority::cases());

    it('has a color for every case', function(QueueJobPriority $priority) {
        expect($priority->color())->toBeString()->not->toBeEmpty();
    })->with(QueueJobPriority::cases());

    it('returns positive defaultMaxAttempts for every case', function(QueueJobPriority $priority) {
        expect($priority->defaultMaxAttempts())->toBeGreaterThan(0);
    })->with(QueueJobPriority::cases());

    it('returns positive defaultTimeoutSeconds for every case', function(QueueJobPriority $priority) {
        expect($priority->defaultTimeoutSeconds())->toBeGreaterThan(0);
    })->with(QueueJobPriority::cases());

    it('Critical has more attempts than Bulk', function() {
        expect(QueueJobPriority::Critical->defaultMaxAttempts())
            ->toBeGreaterThan(QueueJobPriority::Bulk->defaultMaxAttempts());
    });

    it('can round-trip through integer value', function(QueueJobPriority $priority) {
        expect(QueueJobPriority::from($priority->value))->toBe($priority);
    })->with(QueueJobPriority::cases());

    it('tryFrom returns null for unknown value', function() {
        expect(QueueJobPriority::tryFrom(999))->toBeNull();
    });
});
