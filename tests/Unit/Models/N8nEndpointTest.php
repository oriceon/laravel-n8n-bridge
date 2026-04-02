<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\RetryStrategy;
use Oriceon\N8nBridge\Models\N8nCredential;
use Oriceon\N8nBridge\Models\N8nEndpoint;

covers(N8nEndpoint::class);

beforeEach(function() {
    $this->credential = N8nCredential::create(['name' => 'test', 'is_active' => true]);
});

// ── isExpired() ───────────────────────────────────────────────────────────────

describe('N8nEndpoint::isExpired()', function() {
    it('returns false when expires_at is null', function() {
        $endpoint = N8nEndpoint::factory()->create(['expires_at' => null]);
        expect($endpoint->isExpired())->toBeFalse();
    });

    it('returns true when expires_at is in the past', function() {
        $endpoint = N8nEndpoint::factory()->create([
            'expires_at' => now()->subHour(),
        ]);
        expect($endpoint->isExpired())->toBeTrue();
    });

    it('returns false when expires_at is in the future', function() {
        $endpoint = N8nEndpoint::factory()->create([
            'expires_at' => now()->addHour(),
        ]);
        expect($endpoint->isExpired())->toBeFalse();
    });
});

// ── inboundUrl() ──────────────────────────────────────────────────────────────

describe('N8nEndpoint::inboundUrl()', function() {
    it('returns URL with slug', function() {
        $endpoint = N8nEndpoint::factory()->create(['slug' => 'my-endpoint']);
        expect($endpoint->inboundUrl())->toContain('my-endpoint');
    });
});

// ── Scopes ────────────────────────────────────────────────────────────────────

describe('N8nEndpoint scopes', function() {
    it('active() returns only active endpoints', function() {
        N8nEndpoint::factory()->create(['is_active' => true]);
        N8nEndpoint::factory()->create(['is_active' => false]);

        expect(N8nEndpoint::active()->count())->toBe(1);
    });

    it('notExpired() excludes expired endpoints', function() {
        N8nEndpoint::factory()->create(['expires_at' => null]);
        N8nEndpoint::factory()->create(['expires_at' => now()->subHour()]);
        N8nEndpoint::factory()->create(['expires_at' => now()->addHour()]);

        expect(N8nEndpoint::notExpired()->count())->toBe(2);
    });
});

// ── Casts ─────────────────────────────────────────────────────────────────────

it('casts retry_strategy to RetryStrategy enum', function() {
    $endpoint = N8nEndpoint::factory()->create(['retry_strategy' => 'exponential']);
    expect($endpoint->retry_strategy)->toBe(RetryStrategy::Exponential);
});

it('hides hmac_secret from serialization', function() {
    $endpoint = N8nEndpoint::factory()->create([
        'hmac_secret' => 'super-secret',
    ]);

    expect(array_key_exists('hmac_secret', $endpoint->toArray()))->toBeFalse();
});
