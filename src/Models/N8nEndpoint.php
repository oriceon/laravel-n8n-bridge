<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Oriceon\N8nBridge\Concerns\HasDynamicTable;
use Oriceon\N8nBridge\Concerns\HasPublicUuid;
use Oriceon\N8nBridge\Database\Factories\N8nEndpointFactory;
use Oriceon\N8nBridge\Enums\AuthType;
use Oriceon\N8nBridge\Enums\RetryStrategy;

/**
 * Inbound endpoint — for receiving n8n calls.
 *
 * @property int $id
 * @property string $slug
 * @property AuthType $auth_type
 * @property string $handler_class
 * @property string $queue
 * @property array|null $allowed_ips
 * @property bool $verify_hmac
 * @property string|null $hmac_secret
 * @property int $rate_limit
 * @property bool $store_payload
 * @property RetryStrategy $retry_strategy
 * @property int $max_attempts
 * @property bool $is_active
 * @property Carbon|null $expires_at
 */
#[Fillable([
    'uuid',
    'slug',
    'auth_type',
    'handler_class',
    'queue',
    'allowed_ips',
    'verify_hmac',
    'hmac_secret',
    'rate_limit',
    'store_payload',
    'retry_strategy',
    'max_attempts',
    'owner_type',
    'owner_id',
    'expires_at',
    'is_active',
])]
#[Hidden(['hmac_secret'])]
class N8nEndpoint extends Model
{
    use HasDynamicTable;

    /** @use HasFactory<N8nEndpointFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected function casts(): array
    {
        return [
            'auth_type'      => AuthType::class,
            'retry_strategy' => RetryStrategy::class,
            'allowed_ips'    => 'array',
            'verify_hmac'    => 'boolean',
            'hmac_secret'    => 'encrypted',
            'store_payload'  => 'boolean',
            'rate_limit'     => 'integer',
            'max_attempts'   => 'integer',
            'is_active'      => 'boolean',
            'expires_at'     => 'datetime',
        ];
    }

    protected $attributes = [
        'auth_type'      => 'api_key',
        'queue'          => 'default',
        'rate_limit'     => 60,
        'store_payload'  => true,
        'verify_hmac'    => false,
        'max_attempts'   => 3,
        'retry_strategy' => 'exponential',
        'is_active'      => true,
    ];

    // ── Scopes ────────────────────────────────────────────────────────────────

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    #[Scope]
    protected function notExpired(Builder $query): Builder
    {
        return $query->where(function(Builder $q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        });
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    /**
     * Credentials allowed to access this endpoint.
     * Empty collection means any authenticated caller is accepted.
     */
    public function credentials(): BelongsToMany
    {
        $prefix = config('n8n-bridge.table_prefix', 'n8n');

        return $this->belongsToMany(
            N8nCredential::class,
            "{$prefix}__endpoints__credentials",
            'endpoint_id',
            'credential_id'
        );
    }

    public function apiKeys(): HasMany
    {
        return $this->hasMany(N8nApiKey::class, 'endpoint_id');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(N8nDelivery::class, 'endpoint_id');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function activeApiKey(): ?N8nApiKey
    {
        return $this->apiKeys()
            ->where('status', 'active')
            ->latest()
            ->first();
    }

    public function inboundUrl(): string
    {
        $prefix = config('n8n-bridge.inbound.route_prefix', 'n8n/in');

        return url("{$prefix}/{$this->slug}");
    }

    protected function getTableBaseName(): string
    {
        return 'endpoints__lists';
    }

    protected static function newFactory(): N8nEndpointFactory
    {
        return N8nEndpointFactory::new();
    }
}
