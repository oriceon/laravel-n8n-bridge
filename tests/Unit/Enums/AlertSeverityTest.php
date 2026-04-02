<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\AlertSeverity;

covers(AlertSeverity::class);

describe('AlertSeverity', function() {
    it('only Critical triggers paging', function(AlertSeverity $severity, bool $expected) {
        expect($severity->shouldPage())->toBe($expected);
    })->with([
        'critical should page' => [AlertSeverity::Critical, true],
        'error no page'        => [AlertSeverity::Error,    false],
        'warning no page'      => [AlertSeverity::Warning,  false],
        'info no page'         => [AlertSeverity::Info,     false],
    ]);

    it('has a non-empty emoji for every case', function(AlertSeverity $severity) {
        expect($severity->emoji())->toBeString()->not->toBeEmpty();
    })->with(AlertSeverity::cases());

    it('has a hex color for every case', function(AlertSeverity $severity) {
        expect($severity->color())->toStartWith('#');
    })->with(AlertSeverity::cases());

    it('can round-trip through string value', function(AlertSeverity $severity) {
        expect(AlertSeverity::from($severity->value))->toBe($severity);
    })->with(AlertSeverity::cases());

    it('tryFrom returns null for unknown value', function() {
        expect(AlertSeverity::tryFrom('fatal'))->toBeNull();
    });
});
