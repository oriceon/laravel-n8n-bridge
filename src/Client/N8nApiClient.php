<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Client;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Oriceon\N8nBridge\DTOs\N8nExecutionResult;

/**
 * HTTP client for the n8n public REST API v1.
 *
 * Covers: workflows, executions, credentials, tags,
 *         variables, users, source control, audit.
 *
 * PhpStorm-ready: every method is fully typed with doc-blocks.
 *
 * @see https://docs.n8n.io/api/api-reference/
 */
final readonly class N8nApiClient
{
    private PendingRequest $http;

    public function __construct(
        private string $baseUrl,
        private string $apiKey,
        private int $timeout = 30,
        private int $retryTimes = 3,
        private int $retrySleepMs = 500,
    ) {
        $this->http = Http::baseUrl(rtrim($this->baseUrl, '/') . '/api/v1')
            ->withHeaders([
                'X-N8N-API-KEY' => $this->apiKey,
                'Accept'        => 'application/json',
                'Content-Type'  => 'application/json',
            ])
            ->timeout($this->timeout)
            ->retry($this->retryTimes, $this->retrySleepMs, throw: false);
    }

    // ── Workflows ─────────────────────────────────────────────────────────────

    /**
     * @param  array{active?: bool, tags?: string, limit?: int, cursor?: string}  $filters
     *
     * @throws ConnectionException
     * @throws RequestException
     * @return array{data: list<array>, nextCursor: string|null}
     */
    public function listWorkflows(array $filters = []): array
    {
        return $this->get('/workflows', $filters);
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     * @return array<string, mixed>
     */
    public function getWorkflow(string $id): array
    {
        return $this->get("/workflows/{$id}");
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ConnectionException
     * @throws RequestException
     * @return array<string, mixed>
     */
    public function createWorkflow(array $data): array
    {
        return $this->post('/workflows', $data);
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws ConnectionException
     * @throws RequestException
     * @return array<string, mixed>
     */
    public function updateWorkflow(string $id, array $data): array
    {
        return $this->put("/workflows/{$id}", $data);
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function activateWorkflow(string $id): array
    {
        return $this->post("/workflows/{$id}/activate");
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function deactivateWorkflow(string $id): array
    {
        return $this->post("/workflows/{$id}/deactivate");
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function deleteWorkflow(string $id): array
    {
        return $this->delete("/workflows/{$id}");
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function transferWorkflow(string $id, string $projectId): array
    {
        return $this->put("/workflows/{$id}/transfer", ['destinationProjectId' => $projectId]);
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     * @return list<array>
     */
    public function getWorkflowTags(string $id): array
    {
        return $this->get("/workflows/{$id}/tags");
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function updateWorkflowTags(string $id, array $tagIds): array
    {
        return $this->put("/workflows/{$id}/tags", ['tagIds' => $tagIds]);
    }

    // ── Executions ────────────────────────────────────────────────────────────

    /**
     * @param  array{workflowId?: string, status?: string, limit?: int, cursor?: string, includeData?: bool}  $filters
     *
     * @throws ConnectionException
     * @throws RequestException
     * @return array{data: list<array>, nextCursor: string|null}
     */
    public function listExecutions(array $filters = []): array
    {
        return $this->get('/executions', $filters);
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function getExecution(string $id, bool $includeData = false): N8nExecutionResult
    {
        $data = $this->get("/executions/{$id}", ['includeData' => $includeData]);

        return N8nExecutionResult::fromApiResponse($data);
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function deleteExecution(string $id): array
    {
        return $this->delete("/executions/{$id}");
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function retryExecution(string $id, bool $loadWorkflow = false): N8nExecutionResult
    {
        $data = $this->post("/executions/{$id}/retry", ['loadWorkflow' => $loadWorkflow]);

        return N8nExecutionResult::fromApiResponse($data['execution'] ?? $data);
    }

    // ── Credentials ───────────────────────────────────────────────────────────

    /**
     * @throws ConnectionException
     * @throws RequestException
     * @return array<string, mixed>
     */
    public function createCredential(array $data): array
    {
        return $this->post('/credentials', $data);
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function deleteCredential(string $id): array
    {
        return $this->delete("/credentials/{$id}");
    }

    /**
     * @throws ConnectionException
     * @throws RequestException
     * @return array<string, mixed> Schema definition
     */
    public function getCredentialSchema(string $credentialType): array
    {
        return $this->get("/credentials/schema/{$credentialType}");
    }

    // ── Tags ──────────────────────────────────────────────────────────────────

    /**
     * @throws ConnectionException
     * @throws RequestException
     * @return list<array>
     */
    public function listTags(int $limit = 100): array
    {
        return $this->get('/tags', ['limit' => $limit])['data'] ?? [];
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function createTag(string $name): array
    {
        return $this->post('/tags', ['name' => $name]);
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function updateTag(string $id, string $name): array
    {
        return $this->patch("/tags/{$id}", ['name' => $name]);
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function deleteTag(string $id): array
    {
        return $this->delete("/tags/{$id}");
    }

    // ── Variables ─────────────────────────────────────────────────────────────

    /**
     * @throws ConnectionException
     * @throws RequestException
     * @return list<array>
     */
    public function listVariables(): array
    {
        return $this->get('/variables')['data'] ?? [];
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function createVariable(string $key, string $value): array
    {
        return $this->post('/variables', ['key' => $key, 'value' => $value]);
    }

    public function deleteVariable(string $id): void
    {
        $this->delete("/variables/{$id}");
    }

    // ── Users ─────────────────────────────────────────────────────────────────

    /**
     * @throws ConnectionException
     * @throws RequestException
     * @return list<array>
     */
    public function listUsers(int $limit = 100): array
    {
        return $this->get('/users', ['limit' => $limit])['data'] ?? [];
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function getUser(string $idOrEmail): array
    {
        return $this->get("/users/{$idOrEmail}");
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function deleteUser(string $idOrEmail): array
    {
        return $this->delete("/users/{$idOrEmail}");
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function changeUserRole(string $idOrEmail, string $role): array
    {
        return $this->patch("/users/{$idOrEmail}/role", ['newRoleName' => $role]);
    }

    // ── Source Control ────────────────────────────────────────────────────────

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function pullSourceControl(): array
    {
        return $this->post('/source-control/pull');
    }

    // ── Audit ─────────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $categories  e.g. ['credentials', 'database', 'nodes']
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function generateAudit(array $categories = [], int $daysAbandoned = 90): array
    {
        return $this->post('/audit', [
            'additionalOptions' => [
                'categories'            => $categories,
                'daysAbandonedWorkflow' => $daysAbandoned,
            ],
        ]);
    }

    // ── Health ────────────────────────────────────────────────────────────────

    public function healthz(): bool
    {
        try {
            return Http::baseUrl($this->baseUrl)
                ->timeout(5)
                ->get('/healthz')
                ->successful();
        }
        catch (\Throwable) {
            return false;
        }
    }

    public function readiness(): bool
    {
        try {
            return Http::baseUrl($this->baseUrl)
                ->timeout(5)
                ->get('/healthz/readiness')
                ->successful();
        }
        catch (\Throwable) {
            return false;
        }
    }

    // ── Webhook trigger ───────────────────────────────────────────────────────

    /**
     * Trigger a workflow via its webhook path.
     *
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $extraHeaders  auth headers from WebhookAuthService
     */
    public function triggerWebhook(
        string $url,
        array $payload = [],
        string $method = 'POST',
        array $extraHeaders = [],
    ): array {
        $headers = array_merge([
            'Content-Type' => 'application/json',
            'Accept'       => 'application/json',
        ], $extraHeaders);

        $response = Http::withHeaders($headers)
            ->timeout($this->timeout)
            ->{strtolower($method)}($url, $payload);

        $response->throw();

        return $response->json() ?? [];
    }

    // ── Internal HTTP helpers ─────────────────────────────────────────────────

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    private function get(string $path, array $query = []): array
    {
        $response = $this->http->get($path, $query);
        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    private function post(string $path, array $body = []): array
    {
        $response = $this->http->post($path, $body);
        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    private function put(string $path, array $body = []): array
    {
        $response = $this->http->put($path, $body);
        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    private function patch(string $path, array $body = []): array
    {
        $response = $this->http->patch($path, $body);
        $response->throw();

        return $response->json() ?? [];
    }

    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    private function delete(string $path): array
    {
        $response = $this->http->delete($path);
        $response->throw();

        return $response->json() ?? [];
    }
}
