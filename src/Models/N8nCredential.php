<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Oriceon\N8nBridge\Concerns\HasDynamicTable;
use Oriceon\N8nBridge\Concerns\HasPublicUuid;
use Oriceon\N8nBridge\Database\Factories\N8nCredentialFactory;

/**
 * Represents a n8n credential bundle — the auth identity for one n8n instance.
 *
 * One credential = one n8n instance that communicates with your Laravel app.
 * All traffic from that n8n instance — inbound endpoints, tools, queue progress —
 * is authenticated using the same API key attached to this credential.
 *
 * In n8n you configure ONE Header Auth credential:
 *   Name: X-N8N-Key
 *   Value: n8br_sk_xxxxxxxx (the plaintext key)
 *
 * That credential is reused on every HTTP Request node, regardless of
 * whether it is calling /n8n/in, /n8n/tools, or /n8n/queue/progress.
 *
 * @property int $id
 * @property string $name e.g. "Production"
 * @property string|null $description
 * @property string $n8n_instance matches config instances key
 * @property array|null $allowed_ips null = allow all IPs
 * @property bool $is_active
 */
#[Fillable([
    'uuid',
    'name',
    'description',
    'n8n_instance',
    'allowed_ips',
    'is_active',
])]
class N8nCredential extends Model
{
    use HasDynamicTable;

    /** @use HasFactory<N8nCredentialFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected function casts(): array
    {
        return [
            'allowed_ips' => 'array',
            'is_active'   => 'boolean',
        ];
    }

    protected $attributes = [
        'is_active'    => true,
        'n8n_instance' => 'default',
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    /**
     * The active API key for this credential (most recently generated).
     */
    public function activeKey(): HasOne
    {
        return $this->hasOne(N8nApiKey::class, 'credential_id')
            ->whereIn('status', ['active', 'grace'])
            ->latestOfMany('created_at');
    }

    /**
     * All API keys ever generated for this credential.
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(N8nApiKey::class, 'credential_id');
    }

    /**
     * Inbound endpoints associated with this credential.
     */
    public function inboundEndpoints(): BelongsToMany
    {
        $prefix = config('n8n-bridge.table_prefix', 'n8n');

        return $this->belongsToMany(
            N8nEndpoint::class,
            "{$prefix}__endpoints__credentials",
            'credential_id',
            'endpoint_id'
        );
    }

    /**
     * Tools associated with this credential.
     */
    public function tools(): BelongsToMany
    {
        $prefix = config('n8n-bridge.table_prefix', 'n8n');

        return $this->belongsToMany(
            N8nTool::class,
            "{$prefix}__tools__credentials",
            'credential_id',
            'tool_id'
        );
    }

    // ── Key management ────────────────────────────────────────────────────────

    /**
     * Generate a new API key for this credential.
     * Returns [plaintext, N8nApiKey].
     * The plaintext is shown once — store it in n8n credentials.
     *
     * @return array{0: string, 1: N8nApiKey}
     */
    public function generateKey(?string $createdBy = null): array
    {
        return N8nApiKey::generate($this->id, $createdBy);
    }

    /**
     * Rotate: put the current key in a grace period, generate a new one.
     * Returns [new_plaintext, new_N8nApiKey].
     *
     * @return array{0: string, 1: N8nApiKey}
     */
    public function rotateKey(int $gracePeriodSeconds = 300): array
    {
        // Put all active keys into grace
        $this->apiKeys()
            ->where('status', 'active')
            ->each(fn(N8nApiKey $k) => $k->startGracePeriod($gracePeriodSeconds));

        return $this->generateKey();
    }

    /**
     * Verify an incoming plaintext key against this credential's active keys.
     */
    public function verifyKey(string $plaintext): bool
    {
        return $this->apiKeys()
            ->whereIn('status', ['active', 'grace'])
            ->get()
            ->contains(fn(N8nApiKey $k) => $k->verify($plaintext));
    }

    protected function getTableBaseName(): string
    {
        return 'credentials__lists';
    }

    protected static function newFactory(): N8nCredentialFactory
    {
        return N8nCredentialFactory::new();
    }
}
