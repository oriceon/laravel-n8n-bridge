<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;
use Oriceon\N8nBridge\Concerns\HasDynamicTable;
use Oriceon\N8nBridge\Concerns\HasPublicUuid;
use Oriceon\N8nBridge\Enums\ApiKeyStatus;

/**
 * Rotatable API key belonging to an N8nCredential.
 *
 * One credential can have multiple keys (active and grace during rotation).
 * All modules — Inbound, Tools, Queue Progress — authenticate against
 * the same credential keys via CredentialAuthService.
 *
 * @property int                  $id
 * @property int                  $credential_id
 * @property string               $key_hash       SHA-256 of plaintext — never stored in clear
 * @property string               $key_prefix     "n8br_sk_xxxx" — shown to the user for identification
 * @property ApiKeyStatus         $status
 * @property \Carbon\Carbon|null  $grace_until
 * @property string|null          $created_by
 * @property \Carbon\Carbon|null  $last_used_at
 * @property int                  $use_count
 * @property \Carbon\Carbon|null  $revoked_at
 */
#[Fillable([
    'uuid',
    'credential_id',
    'key_hash',
    'key_prefix',
    'status',
    'grace_until',
    'created_by',
    'last_used_at',
    'use_count',
    'revoked_at',
])]
#[Hidden(['key_hash'])]
class N8nApiKey extends Model
{
    use HasDynamicTable;
    use HasPublicUuid;

    protected function casts(): array
    {
        return [
            'status'       => ApiKeyStatus::class,
            'grace_until'  => 'datetime',
            'last_used_at' => 'datetime',
            'revoked_at'   => 'datetime',
            'use_count'    => 'integer',
        ];
    }

    // ── Factory ───────────────────────────────────────────────────────────────

    /**
     * Generate a new API key for a credential.
     * Returns [plaintext, model]. Plaintext is shown once — not recoverable.
     *
     * @param int $credentialId
     * @param string|null $createdBy
     * @return array{0: string, 1: self}
     */
    #[\NoDiscard]
    public static function generate(int $credentialId, ?string $createdBy = null): array
    {
        $raw    = 'n8br_sk_' . Str::random(32);
        $prefix = substr($raw, 0, 12);   // "n8br_sk_xxxx" — visible prefix for identification
        $hash   = hash('sha256', $raw);

        $model = static::create([
            'credential_id' => $credentialId,
            'key_hash'      => $hash,
            'key_prefix'    => $prefix,
            'status'        => ApiKeyStatus::Active,
            'created_by'    => $createdBy,
            'use_count'     => 0,
        ]);

        return [$raw, $model];
    }

    // ── Verification ─────────────────────────────────────────────────────────

    /**
     * @param string $plaintext
     * @return bool
     */
    #[\NoDiscard]
    public function verify(string $plaintext): bool
    {
        if ( ! $this->status->isUsable()) {
            return false;
        }

        if ($this->status === ApiKeyStatus::Grace && $this->grace_until?->isPast()) {
            return false;
        }

        return hash_equals($this->key_hash, hash('sha256', $plaintext));
    }

    public function recordUsage(): void
    {
        $this->increment('use_count');
        $this->update(['last_used_at' => now()]);
    }

    public function revoke(): void
    {
        $this->update([
            'status'     => ApiKeyStatus::Revoked,
            'revoked_at' => now(),
        ]);
    }

    /**
     * @param int $seconds
     * @return void
     */
    public function startGracePeriod(int $seconds = 300): void
    {
        $this->update([
            'status'      => ApiKeyStatus::Grace,
            'grace_until' => now()->addSeconds($seconds),
        ]);
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function credential(): BelongsTo
    {
        return $this->belongsTo(N8nCredential::class, 'credential_id');
    }

    protected function getTableBaseName(): string
    {
        return 'api_keys__lists';
    }
}
