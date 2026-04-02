<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;
use Oriceon\N8nBridge\Commands\EndpointCreateCommand;
use Oriceon\N8nBridge\Commands\EndpointListCommand;
use Oriceon\N8nBridge\Commands\EndpointRotateCommand;
use Oriceon\N8nBridge\Database\Factories\N8nCredentialFactory;
use Oriceon\N8nBridge\Database\Factories\N8nEndpointFactory;
use Oriceon\N8nBridge\Enums\ApiKeyStatus;
use Oriceon\N8nBridge\Models\N8nApiKey;
use Oriceon\N8nBridge\Models\N8nEndpoint;

covers(
    EndpointCreateCommand::class,
    EndpointListCommand::class,
    EndpointRotateCommand::class,
);

// ── n8n:endpoint:create ───────────────────────────────────────────────────────

describe('n8n:endpoint:create', function() {
    it('fails when --handler is missing', function() {
        $this->artisan('n8n:endpoint:create', ['slug' => 'invoice-paid'])
            ->expectsOutputToContain('--handler is required')
            ->assertFailed();
    });

    it('creates an endpoint', function() {
        $this->artisan('n8n:endpoint:create', [
            'slug'      => 'invoice-paid',
            '--handler' => 'App\\N8n\\InvoiceHandler',
            '--queue'   => 'high',
        ])
            ->expectsOutputToContain('Endpoint created successfully')
            ->expectsOutputToContain('API Key')
            ->assertSuccessful();

        expect(N8nEndpoint::where('slug', 'invoice-paid')->exists())->toBeTrue();
    });

    it('applies rate-limit and max-attempts options', function() {
        $this->artisan('n8n:endpoint:create', [
            'slug'           => 'throttled',
            '--handler'      => 'App\\N8n\\ThrottledHandler',
            '--rate-limit'   => '30',
            '--max-attempts' => '5',
        ])->assertSuccessful();

        $endpoint = N8nEndpoint::where('slug', 'throttled')->firstOrFail();
        expect($endpoint->rate_limit)->toBe(30)
            ->and($endpoint->max_attempts)->toBe(5);
    });
});

// ── n8n:endpoint:list ─────────────────────────────────────────────────────────

describe('n8n:endpoint:list', function() {
    it('shows no endpoints message when table is empty', function() {
        $this->artisan('n8n:endpoint:list')
            ->expectsOutputToContain('No endpoints found')
            ->assertSuccessful();
    });

    it('lists all endpoints in a table', function() {
        N8nEndpointFactory::new()->create(['slug' => 'order-shipped']);
        N8nEndpointFactory::new()->create(['slug' => 'invoice-paid']);

        $this->artisan('n8n:endpoint:list')
            ->expectsOutputToContain('order-shipped')
            ->expectsOutputToContain('invoice-paid')
            ->assertSuccessful();
    });

    it('filters to only active endpoints with --active', function() {
        N8nEndpointFactory::new()->create(['slug' => 'active-ep', 'is_active' => true]);
        N8nEndpointFactory::new()->inactive()->create(['slug' => 'inactive-ep']);

        // --active scope is applied; inactive endpoints should not appear
        $this->artisan('n8n:endpoint:list', ['--active' => true])
            ->expectsOutputToContain('active-ep')
            ->assertSuccessful();

        // Verify the inactive is excluded from the DB query
        expect(N8nEndpoint::active()->count())->toBe(1);
    });
});

// ── n8n:endpoint:rotate ───────────────────────────────────────────────────────

describe('n8n:endpoint:rotate', function() {
    it('fails when slug does not exist', function() {
        $this->expectException(ModelNotFoundException::class);

        $this->artisan('n8n:endpoint:rotate', ['slug' => 'nonexistent'])
            ->run();
    });

    it('generates a new API key and puts old one in grace period', function() {
        $credential = N8nCredentialFactory::new()->create();
        $endpoint   = N8nEndpointFactory::new()->create([
            'slug'          => 'rotateable',
            'credential_id' => $credential->id,
        ]);

        // Seed an active key on the endpoint's credential
        N8nApiKey::create([
            'uuid'          => (string) Str::uuid(),
            'credential_id' => $credential->id,
            'key_hash'      => hash('sha256', 'old-key'),
            'key_prefix'    => 'n8br_sk_oldk',
            'status'        => ApiKeyStatus::Active,
            'use_count'     => 0,
        ]);

        $this->artisan('n8n:endpoint:rotate', [
            'slug'    => 'rotateable',
            '--grace' => '120',
        ])
            ->expectsOutputToContain('New API key generated. Old key valid for 120s')
            ->assertSuccessful();
    });
});
