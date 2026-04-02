<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Oriceon\N8nBridge\Concerns\HasDynamicTable;
use Oriceon\N8nBridge\Concerns\HasPublicUuid;
use Oriceon\N8nBridge\Enums\CircuitBreakerState;

/**
 * Circuit breaker state per workflow.
 *
 * @property int $id
 * @property int $workflow_id
 * @property CircuitBreakerState $state
 * @property int $failure_count
 * @property Carbon|null $opened_at
 * @property Carbon|null $half_open_at
 * @property Carbon|null $closed_at
 */
#[Fillable([
    'uuid',
    'workflow_id',
    'state',
    'failure_count',
    'success_count',
    'opened_at',
    'half_open_at',
    'closed_at',
])]
class N8nCircuitBreaker extends Model
{
    use HasDynamicTable;
    use HasPublicUuid;

    protected function casts(): array
    {
        return [
            'state'         => CircuitBreakerState::class,
            'failure_count' => 'integer',
            'success_count' => 'integer',
            'opened_at'     => 'datetime',
            'half_open_at'  => 'datetime',
            'closed_at'     => 'datetime',
        ];
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(N8nWorkflow::class, 'workflow_id');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    public function isOpen(): bool
    {
        return $this->state === CircuitBreakerState::Open;
    }

    public function isClosed(): bool
    {
        return $this->state === CircuitBreakerState::Closed;
    }

    public function allowsRequests(): bool
    {
        return $this->state->allowsRequests();
    }

    public function shouldTransitionToHalfOpen(): bool
    {
        if ($this->state !== CircuitBreakerState::Open) {
            return false;
        }

        $cooldown = config('n8n-bridge.circuit_breaker.cooldown_sec', 60);

        return $this->opened_at?->addSeconds($cooldown)->isPast() ?? false;
    }

    protected function getTableBaseName(): string
    {
        return 'circuit_breakers__lists';
    }
}
