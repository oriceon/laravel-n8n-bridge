<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\StatPeriod;

covers(StatPeriod::class);

describe('StatPeriod', function() {
    it('has a non-empty carbonMethod for every case', function(StatPeriod $period) {
        expect($period->carbonMethod())->toBeString()->not->toBeEmpty();
    })->with(StatPeriod::cases());

    it('has a non-empty groupByFormat for every case', function(StatPeriod $period) {
        expect($period->groupByFormat())->toBeString()->not->toBeEmpty();
    })->with(StatPeriod::cases());

    it('has a non-empty sqlDateFormat for every case', function(StatPeriod $period) {
        expect($period->sqlDateFormat())->toBeString()->not->toBeEmpty();
    })->with(StatPeriod::cases());

    it('Daily uses Y-m-d group format', function() {
        expect(StatPeriod::Daily->groupByFormat())->toBe('Y-m-d');
    });

    it('Monthly uses Y-m group format', function() {
        expect(StatPeriod::Monthly->groupByFormat())->toBe('Y-m');
    });

    it('can round-trip through string value', function(StatPeriod $period) {
        expect(StatPeriod::from($period->value))->toBe($period);
    })->with(StatPeriod::cases());

    it('tryFrom returns null for unknown value', function() {
        expect(StatPeriod::tryFrom('quarterly'))->toBeNull();
    });
});
