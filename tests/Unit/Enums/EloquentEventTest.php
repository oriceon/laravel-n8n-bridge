<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\EloquentEvent;

covers(EloquentEvent::class);

describe('EloquentEvent', function() {
    it('has a label for every case', function(EloquentEvent $event) {
        expect($event->label())->toBeString()->not->toBeEmpty();
    })->with(EloquentEvent::cases());

    it('covers the standard Eloquent lifecycle events', function() {
        $values = array_map(static fn($e) => $e->value, EloquentEvent::cases());

        expect($values)->toContain('created')
            ->toContain('updated')
            ->toContain('deleted')
            ->toContain('saved');
    });

    it('can round-trip through string value', function(EloquentEvent $event) {
        expect(EloquentEvent::from($event->value))->toBe($event);
    })->with(EloquentEvent::cases());

    it('tryFrom returns null for unknown value', function() {
        expect(EloquentEvent::tryFrom('forceDeleted'))->toBeNull();
    });
});
