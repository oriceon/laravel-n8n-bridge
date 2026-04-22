<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Oriceon\N8nBridge\Concerns\HasDynamicTable;
use Oriceon\N8nBridge\Concerns\HasPublicUuid;
use Oriceon\N8nBridge\Database\Factories\N8nToolFactory;

/**
 * A registered n8n Tool — an endpoint n8n calls to interact with your app.
 *
 * Tools accept any HTTP method: GET for data retrieval, POST for actions,
 * PATCH/PUT for updates, DELETE for removals. One tool handler covers all
 * the methods you choose to expose.
 *
 * Authentication: via the attached N8nCredential key (the same key used on all
 * /n8n/* routes — one credential in n8n for everything).
 *
 * Routes:
 *   GET    /n8n/tools/{name}       → handler->get()
 *   GET    /n8n/tools/{name}/{id}  → handler->getById()
 *   POST   /n8n/tools/{name}       → handler->post()
 *   PUT    /n8n/tools/{name}/{id}  → handler->put()
 *   PATCH  /n8n/tools/{name}/{id}  → handler->patch()
 *   DELETE /n8n/tools/{name}/{id}  → handler->delete()
 *
 * @property int $id
 * @property string $name URL slug: /n8n/tools/{name}
 * @property string $label Human-readable name for schema
 * @property string|null $description
 * @property string|null $category Groups tools in OpenAPI schema
 * @property string $handler_class FQDN of N8nToolHandler subclass
 * @property array|null $allowed_methods null = POST only (backward compat)
 * @property array|null $allowed_ips null = allow all IPs
 * @property int $rate_limit requests per minute (0 = unlimited)
 * @property array|null $request_schema JSON Schema for POST/PATCH body
 * @property array|null $response_schema JSON Schema for response
 * @property array|null $examples Example request payloads
 * @property bool $is_active
 */
#[Fillable([
    'uuid',
    'name',
    'label',
    'description',
    'category',
    'handler_class',
    'allowed_methods',
    'allowed_ips',
    'rate_limit',
    'request_schema',
    'response_schema',
    'examples',
    'is_active',
])]
class N8nTool extends Model
{
    use HasDynamicTable;

    /** @use HasFactory<N8nToolFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected function casts(): array
    {
        return [
            'allowed_methods' => 'array',
            'allowed_ips'     => 'array',
            'rate_limit'      => 'integer',
            'request_schema'  => 'array',
            'response_schema' => 'array',
            'examples'        => 'array',
            'is_active'       => 'boolean',
        ];
    }

    protected $attributes = [
        'is_active'  => true,
        'rate_limit' => 120,
    ];

    // ── Scopes ────────────────────────────────────────────────────────────────

    #[Scope]
    protected function active(Builder $q): Builder
    {
        return $q->where('is_active', true);
    }

    #[Scope]
    protected function inCategory(Builder $q, string $category): Builder
    {
        return $q->where('category', $category);
    }

    // ── Method checking ───────────────────────────────────────────────────────

    /**
     * Whether this tool allows a specific HTTP method.
     * null = POST only (backward compat with old single-method tools).
     */
    public function allowsMethod(string $method): bool
    {
        if ($this->allowed_methods === null) {
            return strtoupper($method) === 'POST';
        }

        return in_array(strtoupper($method), $this->allowed_methods, true);
    }

    /**
     * Whether this tool is readable (allows GET).
     */
    public function isReadable(): bool
    {
        return $this->allowsMethod('GET');
    }

    /**
     * Whether this tool is writable (allows POST/PUT/PATCH/DELETE).
     */
    public function isWritable(): bool
    {
        return $this->allowsMethod('POST') ||
            $this->allowsMethod('PUT') ||
            $this->allowsMethod('PATCH') ||
            $this->allowsMethod('DELETE');
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    /**
     * Credentials allowed to access this tool.
     * Empty collection means any authenticated caller is accepted.
     */
    public function credentials(): BelongsToMany
    {
        $prefix = config('n8n-bridge.table_prefix', 'n8n');

        return $this->belongsToMany(
            N8nCredential::class,
            "{$prefix}__tools__credentials",
            'tool_id',
            'credential_id'
        );
    }

    protected function getTableBaseName(): string
    {
        return 'tools__lists';
    }

    protected static function newFactory(): N8nToolFactory
    {
        return N8nToolFactory::new();
    }
}
