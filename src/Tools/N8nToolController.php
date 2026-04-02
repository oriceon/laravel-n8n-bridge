<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Tools;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\RateLimiter;
use Oriceon\N8nBridge\Auth\CredentialAuthService;
use Oriceon\N8nBridge\DTOs\N8nToolRequest;
use Oriceon\N8nBridge\DTOs\N8nToolResponse;
use Oriceon\N8nBridge\Events\N8nToolCalledEvent;
use Oriceon\N8nBridge\Models\N8nTool;

/**
 * Central controller for all /n8n/tools/* routes.
 *
 * Routes:
 *   GET  /n8n/tools/schema            — OpenAPI schema for all active tools
 *   GET  /n8n/tools/{name}            — handler->get()
 *   GET  /n8n/tools/{name}/{id}       — handler->getById()
 *   POST /n8n/tools/{name}            — handler->post()
 *   PUT  /n8n/tools/{name}/{id}       — handler->put()
 *   PATCH /n8n/tools/{name}/{id}      — handler->patch()
 *   DELETE /n8n/tools/{name}/{id}     — handler->delete()
 *
 * Auth pipeline (per request):
 *   1. Find tool by name            → 404 if not found / inactive
 *   2. Check HTTP method allowed    → 405 if the method is not configured
 *   3. Rate limit                   → 429 if exceeded
 *   4. Credential key verification  → 401/403 if the key is missing /wrong
 *   5. IP whitelist                 → 403 if IP not allowed
 *   6. Resolve handler              → 500 if a class is missing
 *   7. Dispatch to method
 *   8. Fire N8nToolCalledEvent
 */
final class N8nToolController extends Controller
{
    public function __construct(
        private readonly CredentialAuthService $auth,
    ) {
    }

    // ── Schema endpoint ───────────────────────────────────────────────────────

    /**
     * GET /n8n/tools/schema
     * Returns an OpenAPI 3 schema covering all active tools.
     * Schema endpoint is public (no auth) — lists names/schemas, not data.
     */
    public function schema(Request $request): JsonResponse
    {
        $tools      = N8nTool::query()->active()->orderBy('category')->orderBy('name')->get();
        $toolPrefix = config('n8n-bridge.tools.route_prefix', 'n8n/tools');

        $schema = [
            'openapi' => '3.0.3',
            'info'    => [
                'title'   => config('app.name') . ' — n8n Tools',
                'version' => '1.0.0',
            ],
            'paths' => [],
        ];

        foreach ($tools as $tool) {
            $basePath = '/' . $toolPrefix . '/' . $tool->name;
            $methods  = $tool->allowed_methods ?? ['POST'];
            $ops      = [];

            foreach ($methods as $method) {
                $method       = strtolower($method);
                $ops[$method] = [
                    'summary'     => $tool->label,
                    'description' => $tool->description ?? '',
                    'operationId' => $tool->name . '_' . $method,
                    'tags'        => [$tool->category ?? 'general'],
                    'security'    => [['WebhookKey' => []]],
                    'responses'   => [
                        '200' => [
                            'description' => 'Success',
                            'content'     => [
                                'application/json' => [
                                    'schema' => $tool->response_schema ?? ['type' => 'object'],
                                ],
                            ],
                        ],
                        '401' => ['description' => 'Unauthorized — invalid or missing webhook key'],
                        '404' => ['description' => 'Resource not found'],
                        '405' => ['description' => 'Method not allowed for this tool'],
                    ],
                ];

                if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
                    $ops[$method]['requestBody'] = [
                        'required' => true,
                        'content'  => [
                            'application/json' => [
                                'schema' => $tool->request_schema ?? ['type' => 'object'],
                            ],
                        ],
                    ];

                    if ($tool->examples) {
                        $ops[$method]['requestBody']['content']['application/json']['examples'] = $tool->examples;
                    }
                }

                if (strtoupper($method) === 'GET') {
                    $ops[$method]['parameters'] = $this->buildGetParameters($tool);
                }
            }

            $schema['paths'][$basePath] = $ops;

            // Add /{id} path if tool supports ID-based operations
            $idMethods = array_intersect($methods, ['GET', 'PUT', 'PATCH', 'DELETE']);

            if ( ! empty($idMethods)) {
                $idOps = [];

                foreach ($idMethods as $method) {
                    $method         = strtolower($method);
                    $idOps[$method] = [
                        'summary'     => $tool->label . ' (by ID)',
                        'operationId' => $tool->name . '_' . $method . '_by_id',
                        'tags'        => [$tool->category ?? 'general'],
                        'security'    => [['WebhookKey' => []]],
                        'parameters'  => [[
                            'name'     => 'id',
                            'in'       => 'path',
                            'required' => true,
                            'schema'   => ['type' => 'string'],
                        ]],
                        'responses' => [
                            '200' => ['description' => 'Success'],
                            '404' => ['description' => 'Not found'],
                        ],
                    ];

                    if (in_array(strtoupper($method), ['PUT', 'PATCH'], true)) {
                        $idOps[$method]['requestBody'] = [
                            'required' => true,
                            'content'  => ['application/json' => [
                                'schema' => $tool->request_schema ?? ['type' => 'object'],
                            ]],
                        ];
                    }
                }
                $schema['paths'][$basePath . '/{id}'] = $idOps;
            }
        }

        $schema['components'] = [
            'securitySchemes' => [
                'WebhookKey' => [
                    'type' => 'apiKey',
                    'in'   => 'header',
                    'name' => 'X-N8N-Key',
                ],
            ],
        ];

        return new JsonResponse($schema, 200, ['Content-Type' => 'application/json']);
    }

    // ── Method dispatchers ────────────────────────────────────────────────────

    public function index(Request $request, string $name): JsonResponse
    {
        [$tool, $handler, $toolRequest, $error] = $this->boot($request, $name, 'GET');

        if ($error) {
            return $error;
        }

        return $this->execute($tool, $handler, fn() => $handler->get($toolRequest), $request);
    }

    public function show(Request $request, string $name, string|int $id): JsonResponse
    {
        [$tool, $handler, $toolRequest, $error] = $this->boot($request, $name, 'GET');

        if ($error) {
            return $error;
        }

        return $this->execute($tool, $handler, fn() => $handler->getById($toolRequest, $id), $request);
    }

    public function store(Request $request, string $name): JsonResponse
    {
        [$tool, $handler, $toolRequest, $error] = $this->boot($request, $name, 'POST');

        if ($error) {
            return $error;
        }

        return $this->execute($tool, $handler, fn() => $handler->post($toolRequest), $request);
    }

    public function replace(Request $request, string $name, string|int $id): JsonResponse
    {
        [$tool, $handler, $toolRequest, $error] = $this->boot($request, $name, 'PUT');

        if ($error) {
            return $error;
        }

        return $this->execute($tool, $handler, fn() => $handler->put($toolRequest, $id), $request);
    }

    public function update(Request $request, string $name, string|int $id): JsonResponse
    {
        [$tool, $handler, $toolRequest, $error] = $this->boot($request, $name, 'PATCH');

        if ($error) {
            return $error;
        }

        return $this->execute($tool, $handler, fn() => $handler->patch($toolRequest, $id), $request);
    }

    public function destroy(Request $request, string $name, string|int $id): JsonResponse
    {
        [$tool, $handler, $toolRequest, $error] = $this->boot($request, $name, 'DELETE');

        if ($error) {
            return $error;
        }

        return $this->execute($tool, $handler, fn() => $handler->delete($toolRequest, $id), $request);
    }

    // ── Boot pipeline ─────────────────────────────────────────────────────────

    private function boot(Request $request, string $name, string $method): array
    {
        // 1. Find tool
        $tool = N8nTool::query()->active()->where('name', $name)->first();

        if ($tool === null) {
            return [null, null, null, new JsonResponse(['error' => 'Tool not found.'], 404)];
        }

        // 2. HTTP method allowed
        if ( ! $tool->allowsMethod($method)) {
            return [null, null, null, new JsonResponse(['error' => 'Method not allowed for this tool.'], 405)];
        }

        // 3. Rate limit
        if ($tool->rate_limit > 0) {
            $key = "n8n-tool:{$name}:" . $request->ip();

            if (RateLimiter::tooManyAttempts($key, $tool->rate_limit)) {
                return [null, null, null, new JsonResponse(['error' => 'Too many requests.'], 429)];
            }

            RateLimiter::hit($key, 60);
        }

        // 4. Credential auth
        if ($tool->credential_id !== null) {
            [$credential, $reason] = $this->auth->authenticate($request);

            if ($credential === null) {
                $status  = $reason === 'ip_not_allowed' ? 403 : 401;
                $message = $reason === 'ip_not_allowed' ? 'Forbidden.' : 'Unauthorized.';

                return [null, null, null, new JsonResponse(['error' => $message], $status)];
            }

            if ($credential->id !== $tool->credential_id) {
                return [null, null, null, new JsonResponse(['error' => 'Unauthorized.'], 401)];
            }
        }

        // 5. Per-tool IP whitelist
        if ( ! empty($tool->allowed_ips) && ! in_array($request->ip(), $tool->allowed_ips, true)) {
            return [null, null, null, new JsonResponse(['error' => 'Forbidden.'], 403)];
        }

        // 6. Resolve handler
        if ( ! class_exists($tool->handler_class)) {
            return [null, null, null, new JsonResponse(
                ['error' => 'Handler class not found.'],
                500
            )];
        }

        if ( ! is_subclass_of($tool->handler_class, N8nToolHandler::class)) {
            return [null, null, null, new JsonResponse(
                ['error' => 'Invalid handler class.'],
                500
            )];
        }

        $handler     = app($tool->handler_class);
        $toolRequest = new N8nToolRequest(
            request: $request,
            toolName: $name,
            callerWorkflowId: $request->header('X-N8N-Workflow-Id'),
        );

        return [$tool, $handler, $toolRequest, null];
    }

    private function execute(
        N8nTool $tool,
        N8nToolHandler $handler,
        \Closure $call,
        Request $request,
    ): JsonResponse {
        $startedAt = microtime(true);

        try {
            /** @var N8nToolResponse $response */
            $response   = $call();
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            event(new N8nToolCalledEvent($tool, $request->all(), $response, $durationMs));

            return $response->toJsonResponse();

        }
        catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);
            $response   = N8nToolResponse::error($e->getMessage());

            event(new N8nToolCalledEvent($tool, $request->all(), $response, $durationMs));

            return $response->toJsonResponse();
        }
    }

    /**
     * @return array[]
     */
    private function buildGetParameters(N8nTool $tool): array
    {
        // Standard GET parameters available on all readable tools
        return [
            ['name' => 'filter', 'in' => 'query', 'description' => 'Key-value filters e.g. filter[status]=paid', 'schema' => ['type' => 'object'], 'style' => 'deepObject', 'explode' => true],
            ['name' => 'search', 'in' => 'query', 'description' => 'Full-text search', 'schema' => ['type' => 'string']],
            ['name' => 'sort',   'in' => 'query', 'description' => 'Sort field(s). Prefix with - for DESC. e.g. -created_at,name', 'schema' => ['type' => 'string']],
            ['name' => 'per_page', 'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 15, 'maximum' => 100]],
            ['name' => 'page',   'in' => 'query', 'schema' => ['type' => 'integer', 'default' => 1]],
            ['name' => 'fields', 'in' => 'query', 'description' => 'Sparse fieldset e.g. id,name,status', 'schema' => ['type' => 'string']],
            ['name' => 'include', 'in' => 'query', 'description' => 'Relations to include e.g. customer,items', 'schema' => ['type' => 'string']],
        ];
    }
}
