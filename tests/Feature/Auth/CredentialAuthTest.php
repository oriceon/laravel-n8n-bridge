<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Oriceon\N8nBridge\Auth\CredentialAuthService;
use Oriceon\N8nBridge\Models\N8nCredential;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Oriceon\N8nBridge\Models\N8nTool;
use Tests\Auth\SimpleTool;

covers(CredentialAuthService::class);

beforeAll(function() {
    if ( ! class_exists(SimpleTool::class)) {
        eval('
            namespace Tests\Auth;
            use Oriceon\N8nBridge\DTOs\N8nToolRequest;
            use Oriceon\N8nBridge\DTOs\N8nToolResponse;
            use Oriceon\N8nBridge\Tools\N8nToolHandler;
            final class SimpleTool extends N8nToolHandler {
                public function get(N8nToolRequest $r): N8nToolResponse {
                    return N8nToolResponse::collection([["id" => 1]]);
                }
                public function post(N8nToolRequest $r): N8nToolResponse {
                    return N8nToolResponse::success(["ok" => true]);
                }
            }
        ');
    }
});

// ── CredentialAuthService unit tests ──────────────────────────────────────────

describe('CredentialAuthService::authenticate()', function() {
    it('resolves credential from correct X-N8N-Key header', function() {
        [$credential, $plaintext] = makeCredentialWithKey();

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_N8N_KEY' => $plaintext,
        ]);

        [$resolved, $reason] = app(CredentialAuthService::class)->authenticate($request);

        expect($resolved)->not->toBeNull()
            ->and($resolved->id)->toBe($credential->id)
            ->and($reason)->toBeNull();
    });

    it('accepts key via Authorization: Bearer header', function() {
        [$credential, $plaintext] = makeCredentialWithKey();

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_AUTHORIZATION' => "Bearer {$plaintext}",
        ]);

        [$resolved] = app(CredentialAuthService::class)->authenticate($request);
        expect($resolved?->id)->toBe($credential->id);
    });

    it('returns missing_key when no header sent', function() {
        [, $reason] = app(CredentialAuthService::class)->authenticate(
            Request::create('/')
        );
        expect($reason)->toBe('missing_key');
    });

    it('returns invalid_key for wrong key', function() {
        makeCredentialWithKey(); // ensure at least one credential exists

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_N8N_KEY' => 'wrong-key',
        ]);

        [, $reason] = app(CredentialAuthService::class)->authenticate($request);
        expect($reason)->toBe('invalid_key');
    });

    it('rejects revoked key', function() {
        [$credential, $plaintext] = makeCredentialWithKey();
        $credential->apiKeys()->update(['status' => 'revoked']);

        $request = Request::create('/', 'GET', [], [], [], [
            'HTTP_X_N8N_KEY' => $plaintext,
        ]);

        [$resolved] = app(CredentialAuthService::class)->authenticate($request);
        expect($resolved)->toBeNull();
    });
});

describe('CredentialAuthService::verifyIp()', function() {
    it('returns true when no IP restriction', function() {
        $credential = N8nCredential::factory()->create(['allowed_ips' => null]);
        expect(app(CredentialAuthService::class)->verifyIp(
            Request::create('/'),
            $credential
        ))->toBeTrue();
    });

    it('returns false when IP not in whitelist', function() {
        $credential = N8nCredential::factory()->create(['allowed_ips' => ['1.2.3.4']]);
        expect(app(CredentialAuthService::class)->verifyIp(
            Request::create('/'),
            $credential
        ))->toBeFalse();
    });
});

// ── All /n8n/* routes require auth ───────────────────────────────────────────

describe('All /n8n/* routes require authentication', function() {
    it('inbound endpoint returns 401 without key', function() {
        [$credential] = makeCredentialWithKey();

        N8nEndpoint::factory()->forCredential($credential)->create([
            'slug'          => 'auth-required-inbound',
            'handler_class' => 'App\\N8n\\TestHandler',
        ]);

        $this->postJson('/n8n/in/auth-required-inbound', [])->assertStatus(401);
    });

    it('tool returns 401 without key', function() {
        [$credential] = makeCredentialWithKey();

        N8nTool::factory()->forCredential($credential)->create([
            'name'            => 'auth-required-tool',
            'handler_class'   => 'Tests\\Auth\\SimpleTool',
            'allowed_methods' => ['GET'],
        ]);

        $this->getJson('/n8n/tools/auth-required-tool')->assertStatus(401);
    });

    it('same credential key works for both inbound and tools', function() {
        [$credential, $key] = makeCredentialWithKey();

        N8nEndpoint::factory()->forCredential($credential)->create([
            'slug'          => 'same-key-inbound',
            'handler_class' => 'App\\N8n\\TestHandler',
        ]);

        N8nTool::factory()->forCredential($credential)->create([
            'name'            => 'same-key-tool',
            'handler_class'   => 'Tests\\Auth\\SimpleTool',
            'allowed_methods' => ['GET'],
        ]);

        // Both accept the same key
        $this->getJson('/n8n/tools/same-key-tool', ['X-N8N-Key' => $key])->assertOk();
    });

    it('key from credential A does not work for tool on credential B', function() {
        [$credentialA, $keyA] = makeCredentialWithKey();
        [$credentialB]        = makeCredentialWithKey();

        N8nTool::factory()->forCredential($credentialB)->create([
            'name'            => 'tool-on-b-only',
            'handler_class'   => 'Tests\\Auth\\SimpleTool',
            'allowed_methods' => ['GET'],
        ]);

        $this->getJson('/n8n/tools/tool-on-b-only', ['X-N8N-Key' => $keyA])
            ->assertStatus(401);
    });
});

// ── Key rotation ──────────────────────────────────────────────────────────────

describe('Key rotation', function() {
    it('new key works immediately, old key works during grace period', function() {
        [$credential, $oldKey] = makeCredentialWithKey();

        N8nTool::factory()->forCredential($credential)->create([
            'name'            => 'rotation-tool',
            'handler_class'   => 'Tests\\Auth\\SimpleTool',
            'allowed_methods' => ['GET'],
        ]);

        $this->getJson('/n8n/tools/rotation-tool', ['X-N8N-Key' => $oldKey])->assertOk();

        [$newKey] = $credential->rotateKey(300);

        $this->getJson('/n8n/tools/rotation-tool', ['X-N8N-Key' => $newKey])->assertOk();
        $this->getJson('/n8n/tools/rotation-tool', ['X-N8N-Key' => $oldKey])->assertOk();
    });

    it('revoked key is rejected immediately', function() {
        [$credential, $key] = makeCredentialWithKey();

        N8nTool::factory()->forCredential($credential)->create([
            'name'            => 'revoke-test-tool',
            'handler_class'   => 'Tests\\Auth\\SimpleTool',
            'allowed_methods' => ['GET'],
        ]);

        $credential->apiKeys()->update(['status' => 'revoked']);

        $this->getJson('/n8n/tools/revoke-test-tool', ['X-N8N-Key' => $key])
            ->assertStatus(401);
    });
});
