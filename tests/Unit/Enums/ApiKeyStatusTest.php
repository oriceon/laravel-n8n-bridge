<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\ApiKeyStatus;

covers(ApiKeyStatus::class);

describe('ApiKeyStatus', function() {
    it('marks active and grace as usable', function(ApiKeyStatus $status, bool $expected) {
        expect($status->isUsable())->toBe($expected);
    })->with([
        'active is usable'   => [ApiKeyStatus::Active,  true],
        'grace is usable'    => [ApiKeyStatus::Grace,   true],
        'revoked not usable' => [ApiKeyStatus::Revoked, false],
    ]);

    it('has a label for every case', function(ApiKeyStatus $status) {
        expect($status->label())->toBeString()->not->toBeEmpty();
    })->with(ApiKeyStatus::cases());

    it('can round-trip through string value', function(ApiKeyStatus $status) {
        expect(ApiKeyStatus::from($status->value))->toBe($status);
    })->with(ApiKeyStatus::cases());

    it('tryFrom returns null for unknown value', function() {
        expect(ApiKeyStatus::tryFrom('expired'))->toBeNull();
    });
});
