<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\CircuitBreakerState;

covers(CircuitBreakerState::class);

describe('CircuitBreakerState', function() {
    it('allows requests when closed or half-open', function(CircuitBreakerState $state, bool $allowed) {
        expect($state->allowsRequests())->toBe($allowed);
    })->with([
        'closed allows'    => [CircuitBreakerState::Closed,   true],
        'half-open allows' => [CircuitBreakerState::HalfOpen, true],
        'open blocks'      => [CircuitBreakerState::Open,     false],
    ]);

    it('has a label for every state', function(CircuitBreakerState $state) {
        expect($state->label())->toBeString()->not->toBeEmpty();
    })->with(CircuitBreakerState::cases());

    it('has a color for every state', function(CircuitBreakerState $state) {
        expect($state->color())->toBeString()->not->toBeEmpty();
    })->with(CircuitBreakerState::cases());

    it('can round-trip through string value', function(CircuitBreakerState $state) {
        expect(CircuitBreakerState::from($state->value))->toBe($state);
    })->with(CircuitBreakerState::cases());

    it('tryFrom returns null for unknown value', function() {
        expect(CircuitBreakerState::tryFrom('unknown'))->toBeNull();
    });
});
