<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\AuthType;

covers(AuthType::class);

describe('AuthType', function() {
    it('requiresSecret for key-based auth types', function(AuthType $type, bool $expected) {
        expect($type->requiresSecret())->toBe($expected);
    })->with([
        'ApiKey requires secret' => [AuthType::ApiKey, true],
        'Bearer requires secret' => [AuthType::Bearer, true],
        'Hmac requires secret'   => [AuthType::Hmac,   true],
        'None no secret'         => [AuthType::None,   false],
    ]);

    it('has a headerName for every case', function(AuthType $type) {
        expect($type->headerName())->toBeString();
    })->with(AuthType::cases());

    it('None has an empty headerName', function() {
        expect(AuthType::None->headerName())->toBe('');
    });

    it('ApiKey uses the expected header', function() {
        expect(AuthType::ApiKey->headerName())->toBe('X-N8N-Key');
    });

    it('can round-trip through string value', function(AuthType $type) {
        expect(AuthType::from($type->value))->toBe($type);
    })->with(AuthType::cases());

    it('tryFrom returns null for unknown value', function() {
        expect(AuthType::tryFrom('jwt'))->toBeNull();
    });
});
