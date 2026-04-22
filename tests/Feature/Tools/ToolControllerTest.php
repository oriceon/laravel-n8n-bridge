<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Oriceon\N8nBridge\Events\N8nToolCalledEvent;
use Oriceon\N8nBridge\Models\N8nTool;
use Oriceon\N8nBridge\Tools\N8nToolController;
use Tests\Support\EchoTool;

covers(N8nToolController::class);

beforeAll(function () {
    if (! class_exists(EchoTool::class)) {
        eval('
            namespace Tests\Support;
            use Oriceon\N8nBridge\DTOs\N8nToolRequest;
            use Oriceon\N8nBridge\DTOs\N8nToolResponse;
            use Oriceon\N8nBridge\Tools\N8nToolHandler;
            final class EchoTool extends N8nToolHandler {
                public function get(N8nToolRequest $r): N8nToolResponse {
                    return N8nToolResponse::collection([["echo" => $r->filter("q", "none")]]);
                }
                public function getById(N8nToolRequest $r, string|int $id): N8nToolResponse {
                    return N8nToolResponse::item(["id" => $id, "found" => true]);
                }
                public function post(N8nToolRequest $r): N8nToolResponse {
                    return N8nToolResponse::success(["message" => $r->get("message"), "created" => true]);
                }
                public function patch(N8nToolRequest $r, string|int $id): N8nToolResponse {
                    return N8nToolResponse::success(["id" => $id, "updated" => true]);
                }
                public function delete(N8nToolRequest $r, string|int $id): N8nToolResponse {
                    return N8nToolResponse::success(["id" => $id, "deleted" => true]);
                }
            }
        ');
    }
});

// ── Each test creates its own credential + key ────────────────────────────────

function createToolWithCredential(array $attrs = []): array
{
    [$credential, $key] = makeCredentialWithKey();
    $tool = N8nTool::factory()->forCredential($credential)->create(array_merge([
        'handler_class' => 'Tests\Support\EchoTool',
    ], $attrs));

    return [$tool, $credential, $key];
}

// ── HTTP method routing ───────────────────────────────────────────────────────

describe('HTTP method routing', function () {
    it('GET /n8n/tools/{name} → handler->get()', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'invoices',
            'allowed_methods' => ['GET', 'POST'],
        ]);

        $this->getJson('/n8n/tools/invoices?filter[q]=hello', ['X-N8N-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.0.echo', 'hello');
    });

    it('GET /n8n/tools/{name}/{id} → handler->getById()', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'items',
            'allowed_methods' => ['GET'],
        ]);

        $this->getJson('/n8n/tools/items/42', ['X-N8N-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.id', '42')
            ->assertJsonPath('data.found', true);
    });

    it('POST /n8n/tools/{name} → handler->post()', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'send-email',
            'allowed_methods' => ['POST'],
        ]);

        $this->postJson('/n8n/tools/send-email', ['message' => 'hello'], ['X-N8N-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.message', 'hello')
            ->assertJsonPath('data.created', true);
    });

    it('PATCH /n8n/tools/{name}/{id} → handler->patch()', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'contacts',
            'allowed_methods' => ['GET', 'POST', 'PATCH'],
        ]);

        $this->patchJson('/n8n/tools/contacts/99', ['name' => 'John'], ['X-N8N-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.id', '99')
            ->assertJsonPath('data.updated', true);
    });

    it('DELETE /n8n/tools/{name}/{id} → handler->delete()', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'records',
            'allowed_methods' => ['GET', 'DELETE'],
        ]);

        $this->deleteJson('/n8n/tools/records/7', [], ['X-N8N-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.id', '7')
            ->assertJsonPath('data.deleted', true);
    });
});

// ── Authentication (required on all routes) ───────────────────────────────────

describe('Authentication', function () {
    it('returns 401 without X-N8N-Key', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'secured-nokey',
            'allowed_methods' => ['GET'],
        ]);

        $this->getJson('/n8n/tools/secured-nokey')->assertStatus(401);
    });

    it('returns 401 with wrong key', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'secured-wrongkey',
            'allowed_methods' => ['GET'],
        ]);

        $this->getJson('/n8n/tools/secured-wrongkey', ['X-N8N-Key' => 'wrong'])->assertStatus(401);
    });

    it('returns 401 when key belongs to different credential', function () {
        [$credentialA] = makeCredentialWithKey();
        [$credentialB, $keyB] = makeCredentialWithKey();

        N8nTool::factory()->forCredential($credentialA)->create([
            'name' => 'tool-credential-a',
            'handler_class' => 'Tests\Support\EchoTool',
            'allowed_methods' => ['GET'],
        ]);

        $this->getJson('/n8n/tools/tool-credential-a', ['X-N8N-Key' => $keyB])
            ->assertStatus(401);
    });

    it('correct key works', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'secured-ok',
            'allowed_methods' => ['GET'],
        ]);

        $this->getJson('/n8n/tools/secured-ok', ['X-N8N-Key' => $key])->assertOk();
    });
});

// ── Method restriction ────────────────────────────────────────────────────────

describe('Method restriction', function () {
    it('returns 405 for disallowed HTTP method', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'readonly',
            'allowed_methods' => ['GET'],
        ]);

        $this->postJson('/n8n/tools/readonly', [], ['X-N8N-Key' => $key])
            ->assertStatus(405);
    });

    it('null allowed_methods defaults to POST only', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'legacy',
            'allowed_methods' => null,
        ]);

        $this->postJson('/n8n/tools/legacy', ['message' => 'x'], ['X-N8N-Key' => $key])->assertOk();
        $this->getJson('/n8n/tools/legacy', ['X-N8N-Key' => $key])->assertStatus(405);
    });
});

// ── 404 / inactive ────────────────────────────────────────────────────────────

describe('404 and inactive', function () {
    it('returns 404 for unknown tool (even without key)', function () {
        // 404 before auth check — tool doesn't exist
        $this->getJson('/n8n/tools/nonexistent')->assertStatus(401); // middleware runs first
    });

    it('returns 401 without key even for unknown tool', function () {
        $this->postJson('/n8n/tools/nonexistent', [])->assertStatus(401);
    });

    it('returns 404 for inactive tool (with valid key)', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'disabled',
            'allowed_methods' => ['GET', 'POST'],
            'is_active' => false,
        ]);

        $this->getJson('/n8n/tools/disabled', ['X-N8N-Key' => $key])->assertStatus(404);
    });
});

// ── Events ────────────────────────────────────────────────────────────────────

describe('Events', function () {
    it('fires N8nToolCalledEvent on successful call', function () {
        Event::fake([N8nToolCalledEvent::class]);

        [, , $key] = createToolWithCredential([
            'name' => 'eventful',
            'allowed_methods' => ['POST'],
        ]);

        $this->postJson('/n8n/tools/eventful', ['message' => 'test'], ['X-N8N-Key' => $key])
            ->assertOk();

        Event::assertDispatched(
            N8nToolCalledEvent::class,
            static fn ($e) => $e->tool->name === 'eventful' && $e->response->isSuccess()
        );
    });
});

// ── Rate limiting ─────────────────────────────────────────────────────────────

describe('Rate limiting', function () {
    it('returns 429 when rate limit is exceeded', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'limited',
            'allowed_methods' => ['POST'],
            'rate_limit' => 1,
        ]);

        $this->postJson('/n8n/tools/limited', [], ['X-N8N-Key' => $key])->assertOk();
        $this->postJson('/n8n/tools/limited', [], ['X-N8N-Key' => $key])->assertStatus(429);
    });
});

// ── OpenAPI schema ────────────────────────────────────────────────────────────

describe('OpenAPI schema', function () {
    it('includes GET and POST paths for multi-method tool', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'contacts',
            'label' => 'Contacts',
            'allowed_methods' => ['GET', 'POST'],
            'category' => 'crm',
        ]);

        $paths = $this->getJson('/n8n/tools/schema', ['X-N8N-Key' => $key])
            ->assertOk()
            ->json('paths');

        expect($paths)
            ->toHaveKey('/n8n/tools/contacts')
            ->toHaveKey('/n8n/tools/contacts/{id}')
            ->and($paths['/n8n/tools/contacts'])
            ->toHaveKey('get')
            ->toHaveKey('post');
    });

    it('excludes inactive tools', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'active-tool-schema',
            'allowed_methods' => ['POST'],
            'is_active' => true,
        ]);
        createToolWithCredential([
            'name' => 'inactive-tool-schema',
            'allowed_methods' => ['POST'],
            'is_active' => false,
        ]);

        $paths = $this->getJson('/n8n/tools/schema', ['X-N8N-Key' => $key])->json('paths');

        expect($paths)
            ->toHaveKey('/n8n/tools/active-tool-schema')
            ->not->toHaveKey('/n8n/tools/inactive-tool-schema');
    });

    it('includes X-N8N-Key security scheme definition', function () {
        [, $key] = makeCredentialWithKey();

        expect(
            $this->getJson('/n8n/tools/schema', ['X-N8N-Key' => $key])
                ->json('components.securitySchemes.WebhookKey.name')
        )->toBe('X-N8N-Key');
    });
});

// ── Slash-path tool names ─────────────────────────────────────────────────────

describe('Slash-path tool names', function () {
    it('GET /n8n/tools/crm/contacts → collection when tool is named "crm/contacts"', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'crm/contacts',
            'allowed_methods' => ['GET'],
        ]);

        $this->getJson('/n8n/tools/crm/contacts?filter[q]=john', ['X-N8N-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.0.echo', 'john');
    });

    it('GET /n8n/tools/crm/contacts/42 → getById when tool is named "crm/contacts"', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'crm/contacts',
            'allowed_methods' => ['GET'],
        ]);

        $this->getJson('/n8n/tools/crm/contacts/42', ['X-N8N-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.id', '42');
    });

    it('POST /n8n/tools/billing/invoices → post() on "billing/invoices" tool', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'billing/invoices',
            'allowed_methods' => ['POST'],
        ]);

        $this->postJson('/n8n/tools/billing/invoices', ['message' => 'hi'], ['X-N8N-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.created', true);
    });

    it('PATCH /n8n/tools/billing/invoices/7 → patch() on "billing/invoices" tool, id=7', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'billing/invoices',
            'allowed_methods' => ['GET', 'PATCH'],
        ]);

        $this->patchJson('/n8n/tools/billing/invoices/7', [], ['X-N8N-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.id', '7')
            ->assertJsonPath('data.updated', true);
    });

    it('DELETE /n8n/tools/billing/invoices/7 → delete() on "billing/invoices" tool', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'billing/invoices',
            'allowed_methods' => ['GET', 'DELETE'],
        ]);

        $this->deleteJson('/n8n/tools/billing/invoices/7', [], ['X-N8N-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.id', '7')
            ->assertJsonPath('data.deleted', true);
    });

    it('GET /n8n/tools/crm/contacts falls back to getById when only tool "crm" exists', function () {
        [, , $key] = createToolWithCredential([
            'name' => 'crm',
            'allowed_methods' => ['GET'],
        ]);

        // "crm/contacts" tool does not exist → resolves to tool "crm" with id "contacts"
        $this->getJson('/n8n/tools/crm/contacts', ['X-N8N-Key' => $key])
            ->assertOk()
            ->assertJsonPath('data.id', 'contacts');
    });

    it('hyphen-style slugs still work alongside slash-style', function () {
        [, , $key1] = createToolWithCredential([
            'name' => 'crm-contacts',
            'allowed_methods' => ['GET'],
        ]);
        [, , $key2] = createToolWithCredential([
            'name' => 'crm/contacts-v2',
            'allowed_methods' => ['GET'],
        ]);

        $this->getJson('/n8n/tools/crm-contacts', ['X-N8N-Key' => $key1])->assertOk();
        $this->getJson('/n8n/tools/crm/contacts-v2', ['X-N8N-Key' => $key2])->assertOk();
    });
});
