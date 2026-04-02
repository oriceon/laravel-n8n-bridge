<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Oriceon\N8nBridge\Commands\WorkflowAuthSetupCommand;
use Oriceon\N8nBridge\Enums\WebhookAuthType;
use Oriceon\N8nBridge\Models\N8nWorkflow;

covers(WorkflowAuthSetupCommand::class);

beforeEach(function() {
    $this->workflow = N8nWorkflow::factory()->create([
        'name'      => 'Test Workflow',
        'auth_type' => WebhookAuthType::None,
        'auth_key'  => null,
    ]);
});

it('configures header_token auth and outputs the key', function() {
    $this->artisan('n8n:workflow:auth-setup', [
        'workflow' => 'Test Workflow',
        '--type'   => 'header_token',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('✅')
        ->expectsOutputToContain('X-N8N-Workflow-Key');

    $workflow = $this->workflow->fresh();

    expect($workflow->auth_type)->toBe(WebhookAuthType::HeaderToken)
        ->and($workflow->auth_key)->not->toBeNull()
        ->and(strlen($workflow->auth_key))->toBe(64); // 32 bytes hex
});

it('configures bearer auth', function() {
    $this->artisan('n8n:workflow:auth-setup', [
        'workflow' => 'Test Workflow',
        '--type'   => 'bearer',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Bearer');

    expect($this->workflow->fresh()->auth_type)->toBe(WebhookAuthType::Bearer);
});

it('configures hmac_sha256 auth', function() {
    $this->artisan('n8n:workflow:auth-setup', [
        'workflow' => 'Test Workflow',
        '--type'   => 'hmac_sha256',
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('X-N8N-Signature');

    expect($this->workflow->fresh()->auth_type)->toBe(WebhookAuthType::HmacSha256);
});

it('removes auth when type is none', function() {
    $this->workflow->update([
        'auth_type' => WebhookAuthType::Bearer,
        'auth_key'  => 'somekey',
    ]);

    $this->artisan('n8n:workflow:auth-setup', [
        'workflow' => 'Test Workflow',
        '--type'   => 'none',
    ])->assertSuccessful();

    $workflow = $this->workflow->fresh();

    expect($workflow->auth_type)->toBe(WebhookAuthType::None)
        ->and($workflow->auth_key)->toBeNull();
});

it('uses provided key instead of generating one', function() {
    $customKey = str_repeat('a', 64);

    $this->artisan('n8n:workflow:auth-setup', [
        'workflow' => 'Test Workflow',
        '--type'   => 'header_token',
        '--key'    => $customKey,
    ])->assertSuccessful();

    expect($this->workflow->fresh()->auth_key)->toBe($customKey);
});

it('shows current config with --show flag', function() {
    $this->workflow->update([
        'auth_type' => WebhookAuthType::Bearer,
        'auth_key'  => 'somekey',
    ]);

    $this->artisan('n8n:workflow:auth-setup', [
        'workflow' => 'Test Workflow',
        '--show'   => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('Bearer')
        ->expectsOutputToContain('yes (encrypted)');
});

it('shows no when auth_key is not set with --show flag', function() {
    $this->artisan('n8n:workflow:auth-setup', [
        'workflow' => 'Test Workflow',
        '--show'   => true,
    ])
        ->assertSuccessful()
        ->expectsOutputToContain('no');
});

it('returns failure for unknown workflow', function() {
    $this->artisan('n8n:workflow:auth-setup', [
        'workflow' => 'nonexistent-workflow',
    ])->assertFailed();
});

it('returns failure for invalid auth type', function() {
    $this->artisan('n8n:workflow:auth-setup', [
        'workflow' => 'Test Workflow',
        '--type'   => 'invalid_type',
    ])->assertFailed();
});

it('finds workflow by uuid', function() {
    $this->artisan('n8n:workflow:auth-setup', [
        'workflow' => $this->workflow->uuid,
        '--type'   => 'bearer',
    ])->assertSuccessful();

    expect($this->workflow->fresh()->auth_type)->toBe(WebhookAuthType::Bearer);
});

it('stores key encrypted in database', function() {
    $plaintext = str_repeat('b', 64);

    $this->artisan('n8n:workflow:auth-setup', [
        'workflow' => 'Test Workflow',
        '--type'   => 'header_token',
        '--key'    => $plaintext,
    ])->assertSuccessful();

    // Without decrypting
    $raw = DB::table('n8n__workflows__lists')
        ->where('id', $this->workflow->id)
        ->value('auth_key');

    // The raw value in the database should not be the plaintext key, confirming it is encrypted
    expect($raw)->not->toBe($plaintext)
        ->and($raw)->toContain('eyJ'); // Laravel encrypt() prefix base64
});
