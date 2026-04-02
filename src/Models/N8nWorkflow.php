<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Oriceon\N8nBridge\Concerns\HasDynamicTable;
use Oriceon\N8nBridge\Concerns\HasPublicUuid;
use Oriceon\N8nBridge\Database\Factories\N8nWorkflowFactory;
use Oriceon\N8nBridge\Enums\HttpMethod;
use Oriceon\N8nBridge\Enums\WebhookAuthType;
use Oriceon\N8nBridge\Enums\WebhookMode;

/**
 * @property int $id
 * @property string $uuid
 * @property string|null $n8n_id
 * @property string $n8n_instance
 * @property string $name
 * @property string|null $description
 * @property string|null $webhook_path
 * @property WebhookMode $webhook_mode
 * @property WebhookAuthType $auth_type
 * @property string|null $auth_key
 * @property HttpMethod $http_method
 * @property array|null $tags
 * @property array|null $nodes
 * @property array|null $meta
 * @property int|null $estimated_duration_ms
 * @property int $estimated_sample_count
 * @property Carbon|null $estimated_updated_at
 * @property int|null $rate_limit
 * @property bool $is_active
 * @property Carbon|null $last_synced_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'uuid',
    'n8n_id',
    'n8n_instance',
    'name',
    'description',
    'webhook_path',
    'webhook_mode',
    'auth_type',
    'auth_key',
    'http_method',
    'tags',
    'nodes',
    'meta',
    'owner_type',
    'owner_id',
    'estimated_duration_ms',
    'estimated_sample_count',
    'estimated_updated_at',
    'rate_limit',
    'is_active',
    'last_synced_at',
    'created_at',
    'updated_at',
])]
class N8nWorkflow extends Model
{
    use HasDynamicTable;

    /** @use HasFactory<N8nWorkflowFactory> */
    use HasFactory;

    use HasPublicUuid;
    use SoftDeletes;

    protected $attributes = [
        'n8n_instance' => 'default',
        'auth_type'    => 'none',
        'http_method'  => 'POST',
        'webhook_mode' => 'auto',
        'is_active'    => true,
    ];

    protected function casts(): array
    {
        return [
            'auth_type'              => WebhookAuthType::class,
            'auth_key'               => 'encrypted',
            'http_method'            => HttpMethod::class,
            'webhook_mode'           => WebhookMode::class,
            'tags'                   => 'array',
            'nodes'                  => 'array',
            'meta'                   => 'array',
            'rate_limit'             => 'integer',
            'estimated_duration_ms'  => 'integer',
            'estimated_sample_count' => 'integer',
            'estimated_updated_at'   => 'datetime',
            'is_active'              => 'boolean',
            'last_synced_at'         => 'datetime',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    #[Scope]
    protected function inactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    #[Scope]
    protected function forInstance(Builder $query, string $instance = 'default'): Builder
    {
        return $query->where('n8n_instance', $instance);
    }

    #[Scope]
    protected function withTag(Builder $query, string $tag): Builder
    {
        return $query->whereJsonContains('tags', $tag);
    }

    #[Scope]
    protected function synced(Builder $query): Builder
    {
        return $query->whereNotNull('n8n_id');
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function deliveries(): HasMany
    {
        return $this->hasMany(N8nDelivery::class, 'workflow_id');
    }

    public function stats(): HasMany
    {
        return $this->hasMany(N8nStat::class, 'workflow_id');
    }

    public function circuitBreaker(): HasOne
    {
        return $this->hasOne(N8nCircuitBreaker::class, 'workflow_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(N8nEventSubscription::class, 'workflow_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Resolve the full webhook URL for this workflow.
     *
     * Respects the webhook_mode setting:
     *   - Auto       → /webhook-test in non-production, /webhook in production
     *   - Production → always /webhook (workflow must be active in n8n)
     *   - Test       → always /webhook-test (n8n editor must be listening)
     *
     * The test base URL is derived from the configured webhook_base_url by
     * replacing the trailing /webhook segment with /webhook-test. You can
     * override this per-instance via `webhook_test_base_url` in the config.
     *
     * @throws \RuntimeException if the instance is not configured
     */
    public function resolveWebhookUrl(): string
    {
        $instance = config("n8n-bridge.instances.{$this->n8n_instance}");

        if ($instance === null) {
            throw new \RuntimeException("n8n instance [{$this->n8n_instance}] not configured.");
        }

        $useTest = match ($this->webhook_mode) {
            WebhookMode::Test       => true,
            WebhookMode::Production => false,
            WebhookMode::Auto       => ! app()->isProduction(),
        };

        if ($useTest) {
            $base = $instance['webhook_test_base_url']
                ?? self::deriveTestBaseUrl($instance['webhook_base_url']);
        }
        else {
            $base = $instance['webhook_base_url'];
        }

        return rtrim($base, '/') . '/' . ltrim($this->webhook_path ?? '', '/');
    }

    /**
     * Derive the test webhook base URL from the production one.
     *
     * n8n convention: /webhook → /webhook-test
     * e.g. https://n8n.example.com/webhook → https://n8n.example.com/webhook-test
     */
    private static function deriveTestBaseUrl(string $webhookBaseUrl): string
    {
        $normalized = rtrim($webhookBaseUrl, '/');

        if (str_ends_with($normalized, '/webhook')) {
            return substr($normalized, 0, -8) . '/webhook-test';
        }

        // Fallback for non-standard paths: append -test suffix
        return $normalized . '-test';
    }

    public function isSynced(): bool
    {
        return $this->n8n_id !== null;
    }

    public function hasEstimatedDuration(): bool
    {
        return $this->estimated_duration_ms !== null && $this->estimated_duration_ms > 0;
    }

    /** Whether this workflow has outbound authentication configured. */
    public function hasWebhookAuth(): bool
    {
        return $this->auth_type !== WebhookAuthType::None &&
            ! empty($this->auth_key);
    }

    /**
     * Effective outbound rate limit (requests/min).
     * Returns the per-workflow override if set, otherwise falls back to the
     * global config value. 0 means unlimited.
     */
    public function effectiveRateLimit(): int
    {
        return $this->rate_limit
            ?? (int) config('n8n-bridge.outbound.rate_limit', 0);
    }

    public function estimatedDurationLabel(): ?string
    {
        if ( ! $this->hasEstimatedDuration()) {
            return null;
        }
        $s = $this->estimated_duration_ms / 1000;

        return $s < 60 ? '~' . round($s, 1) . 's' : '~' . round($s / 60, 1) . 'm';
    }

    protected function getTableBaseName(): string
    {
        return 'workflows__lists';
    }

    protected static function newFactory(): N8nWorkflowFactory
    {
        return N8nWorkflowFactory::new();
    }
}
