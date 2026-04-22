<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Commands\Credential\CredentialAttachCommand;
use Oriceon\N8nBridge\Commands\Credential\CredentialCreateCommand;
use Oriceon\N8nBridge\Commands\Credential\CredentialListCommand;
use Oriceon\N8nBridge\Commands\Credential\CredentialRotateCommand;
use Oriceon\N8nBridge\Database\Factories\N8nCredentialFactory;
use Oriceon\N8nBridge\Database\Factories\N8nEndpointFactory;
use Oriceon\N8nBridge\Database\Factories\N8nToolFactory;
use Oriceon\N8nBridge\Models\N8nCredential;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Oriceon\N8nBridge\Models\N8nTool;

covers(
    CredentialCreateCommand::class,
    CredentialListCommand::class,
    CredentialAttachCommand::class,
    CredentialRotateCommand::class,
);

// ── n8n:credential:create ─────────────────────────────────────────────────────

describe('n8n:credential:create', function () {
    it('creates a credential and outputs the API key', function () {
        $this->artisan('n8n:credential:create', ['name' => 'Production'])
            ->expectsOutputToContain('Credential created')
            ->expectsOutputToContain('API Key')
            ->assertSuccessful();

        expect(N8nCredential::where('name', 'Production')->exists())->toBeTrue();
    });

    it('stores allowed IPs when --ips is given', function () {
        $this->artisan('n8n:credential:create', [
            'name' => 'IP-Locked Credential',
            '--ips' => '203.0.113.1,203.0.113.2',
        ])->assertSuccessful();

        $credential = N8nCredential::where('name', 'IP-Locked Credential')->first();
        expect($credential->allowed_ips)->toBe(['203.0.113.1', '203.0.113.2']);
    });

    it('stores description and instance', function () {
        $this->artisan('n8n:credential:create', [
            'name' => 'Staging',
            '--instance' => 'staging',
            '--description' => 'Staging credential',
        ])->assertSuccessful();

        $credential = N8nCredential::where('name', 'Staging')->first();
        expect($credential->n8n_instance)->toBe('staging')
            ->and($credential->description)->toBe('Staging credential');
    });
});

// ── n8n:credential:list ───────────────────────────────────────────────────────

describe('n8n:credential:list', function () {
    it('shows no credentials message when empty', function () {
        $this->artisan('n8n:credential:list')
            ->expectsOutputToContain('No credentials registered')
            ->assertSuccessful();
    });

    it('lists credentials in a table', function () {
        N8nCredentialFactory::new()->create(['name' => 'Production']);
        N8nCredentialFactory::new()->create(['name' => 'Staging']);

        $this->artisan('n8n:credential:list')
            ->expectsOutputToContain('Production')
            ->expectsOutputToContain('Staging')
            ->assertSuccessful();
    });
});

// ── n8n:credential:attach ─────────────────────────────────────────────────────

describe('n8n:credential:attach', function () {
    it('fails when credential id does not exist', function () {
        $this->artisan('n8n:credential:attach', ['id' => 1])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    });

    it('warns and fails when nothing is attached', function () {
        $credential = N8nCredentialFactory::new()->create();

        $this->artisan('n8n:credential:attach', ['id' => $credential->id])
            ->expectsOutputToContain('Nothing was attached')
            ->assertFailed();
    });

    it('attaches to all endpoints and tools with --all', function () {
        $oldCredential = N8nCredentialFactory::new()->create();
        $newCredential = N8nCredentialFactory::new()->create();
        $endpoint = N8nEndpointFactory::new()->forCredential($oldCredential)->create();
        $tool = N8nToolFactory::new()->forCredential($oldCredential)->create();

        $this->artisan('n8n:credential:attach', ['id' => $newCredential->id, '--all' => true])
            ->expectsOutputToContain('attached to all')
            ->assertSuccessful();

        // Both credentials now attached
        expect(N8nEndpoint::find($endpoint->id)->credentials()->pluck('id'))
            ->toContain($newCredential->id)
            ->and(N8nTool::find($tool->id)->credentials()->pluck('id'))
            ->toContain($newCredential->id);
    });

    it('attaches to a specific inbound endpoint by slug', function () {
        $credential = N8nCredentialFactory::new()->create();
        N8nEndpointFactory::new()->create(['slug' => 'invoice-paid']);

        $this->artisan('n8n:credential:attach', [
            'id' => $credential->id,
            '--inbound' => ['invoice-paid'],
        ])
            ->expectsOutputToContain('invoice-paid')
            ->assertSuccessful();

        expect(
            N8nEndpoint::where('slug', 'invoice-paid')->first()->credentials()->pluck('id')
        )->toContain($credential->id);
    });

    it('warns when inbound slug is not found', function () {
        $credential = N8nCredentialFactory::new()->create();

        $this->artisan('n8n:credential:attach', [
            'id' => $credential->id,
            '--inbound' => ['nonexistent-slug'],
        ])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    });

    it('attaches to a specific tool by name', function () {
        $credential = N8nCredentialFactory::new()->create();
        N8nToolFactory::new()->create(['name' => 'invoices']);

        $this->artisan('n8n:credential:attach', [
            'id' => $credential->id,
            '--tool' => ['invoices'],
        ])
            ->expectsOutputToContain('invoices')
            ->assertSuccessful();

        expect(
            N8nTool::where('name', 'invoices')->first()->credentials()->pluck('id')
        )->toContain($credential->id);
    });

    it('detaches from a specific inbound endpoint by slug', function () {
        $credential = N8nCredentialFactory::new()->create();
        $endpoint = N8nEndpointFactory::new()->forCredential($credential)->create(['slug' => 'detach-ep']);

        expect($endpoint->credentials()->pluck('id'))->toContain($credential->id);

        $this->artisan('n8n:credential:attach', [
            'id' => $credential->id,
            '--detach-inbound' => ['detach-ep'],
        ])
            ->expectsOutputToContain('detach-ep')
            ->assertSuccessful();

        expect($endpoint->fresh()->credentials()->pluck('id'))->not->toContain($credential->id);
    });

    it('detaches from all with --detach-all', function () {
        $credential = N8nCredentialFactory::new()->create();
        $endpoint = N8nEndpointFactory::new()->forCredential($credential)->create();
        $tool = N8nToolFactory::new()->forCredential($credential)->create();

        $this->artisan('n8n:credential:attach', [
            'id' => $credential->id,
            '--detach-all' => true,
        ])
            ->expectsOutputToContain('detached from all')
            ->assertSuccessful();

        expect($endpoint->fresh()->credentials()->count())->toBe(0)
            ->and($tool->fresh()->credentials()->count())->toBe(0);
    });

    it('allows multiple credentials on the same endpoint', function () {
        $credA = N8nCredentialFactory::new()->create();
        $credB = N8nCredentialFactory::new()->create();
        N8nEndpointFactory::new()->create(['slug' => 'multi-cred-ep']);

        $this->artisan('n8n:credential:attach', ['id' => $credA->id, '--inbound' => ['multi-cred-ep']])->assertSuccessful();
        $this->artisan('n8n:credential:attach', ['id' => $credB->id, '--inbound' => ['multi-cred-ep']])->assertSuccessful();

        $ids = N8nEndpoint::where('slug', 'multi-cred-ep')->first()->credentials()->pluck('id');
        expect($ids)->toContain($credA->id)->toContain($credB->id);
    });
});

// ── n8n:credential:rotate ─────────────────────────────────────────────────────

describe('n8n:credential:rotate', function () {
    it('fails when credential id is not found', function () {
        $this->artisan('n8n:credential:rotate', ['id' => 99999])
            ->expectsOutputToContain('not found')
            ->assertFailed();
    });

    it('generates a new key and outputs it', function () {
        $credential = N8nCredentialFactory::new()->create();

        // seed an initial key
        $credential->generateKey();

        $this->artisan('n8n:credential:rotate', ['id' => $credential->id, '--grace' => '120'])
            ->expectsOutputToContain('Key rotated')
            ->expectsOutputToContain('New API Key')
            ->expectsOutputToContain('120 seconds')
            ->assertSuccessful();
    });
});
