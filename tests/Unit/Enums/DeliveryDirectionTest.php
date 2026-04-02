<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\DeliveryDirection;

covers(DeliveryDirection::class);

describe('DeliveryDirection', function() {
    it('has the four expected cases', function() {
        $values = array_map(fn($d) => $d->value, DeliveryDirection::cases());

        expect($values)->toContain('inbound')
            ->toContain('outbound')
            ->toContain('tool')
            ->toContain('queue');
    });

    it('can round-trip through string value', function(DeliveryDirection $direction) {
        expect(DeliveryDirection::from($direction->value))->toBe($direction);
    })->with(DeliveryDirection::cases());

    it('tryFrom returns null for unknown value', function() {
        expect(DeliveryDirection::tryFrom('webhook'))->toBeNull();
    });
});
