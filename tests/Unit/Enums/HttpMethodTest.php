<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\HttpMethod;

covers(HttpMethod::class);

describe('HttpMethod', function() {
    it('correctly identifies methods that have a body', function(HttpMethod $method, bool $expected) {
        expect($method->hasBody())->toBe($expected);
    })->with([
        'POST has body'  => [HttpMethod::Post,   true],
        'PUT has body'   => [HttpMethod::Put,    true],
        'PATCH has body' => [HttpMethod::Patch,  true],
        'GET no body'    => [HttpMethod::Get,    false],
        'DELETE no body' => [HttpMethod::Delete, false],
    ]);

    it('values are uppercase HTTP verbs', function(HttpMethod $method) {
        expect($method->value)->toBe(strtoupper($method->value));
    })->with(HttpMethod::cases());

    it('can round-trip through string value', function(HttpMethod $method) {
        expect(HttpMethod::from($method->value))->toBe($method);
    })->with(HttpMethod::cases());

    it('tryFrom returns null for unknown value', function() {
        expect(HttpMethod::tryFrom('CONNECT'))->toBeNull();
    });
});
