<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Oriceon\N8nBridge\Concerns\HasDynamicTable;
use Oriceon\N8nBridge\Concerns\HasPublicUuid;
use Oriceon\N8nBridge\Database\Factories\N8nDeliveryFactory;
use Oriceon\N8nBridge\Enums\DeliveryDirection;
use Oriceon\N8nBridge\Enums\DeliveryStatus;

/**
 * Complete delivery log — every inbound/outbound/tool call.
 *
 * @property int $id
 * @property int $workflow_id
 * @property int|null $endpoint_id
 * @property DeliveryDirection $direction
 * @property DeliveryStatus $status
 * @property string|null $idempotency_key
 * @property array|null $payload
 * @property array|null $response
 * @property int|null $http_status
 * @property int|null $duration_ms
 * @property int $attempts
 * @property string|null $error_message
 * @property string|null $error_class
 * @property int|null $n8n_execution_id
 * @property Carbon|null $processed_at
 */
#[Fillable([
    'uuid',
    'workflow_id',
    'endpoint_id',
    'direction',
    'status',
    'idempotency_key',
    'payload',
    'response',
    'http_status',
    'duration_ms',
    'attempts',
    'error_message',
    'error_class',
    'n8n_execution_id',
    'processed_at',
])]
class N8nDelivery extends Model
{
    use HasDynamicTable;

    /** @use HasFactory<N8nDeliveryFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected function casts(): array
    {
        return [
            'direction'        => DeliveryDirection::class,
            'status'           => DeliveryStatus::class,
            'payload'          => 'array',
            'response'         => 'array',
            'n8n_execution_id' => 'integer',
            'http_status'      => 'integer',
            'duration_ms'      => 'integer',
            'attempts'         => 'integer',
            'processed_at'     => 'datetime',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    #[Scope]
    protected function inbound(Builder $query): Builder
    {
        return $query->where('direction', DeliveryDirection::Inbound->value);
    }

    #[Scope]
    protected function outbound(Builder $query): Builder
    {
        return $query->where('direction', DeliveryDirection::Outbound->value);
    }

    #[Scope]
    protected function tools(Builder $query): Builder
    {
        return $query->where('direction', DeliveryDirection::Tool->value);
    }

    #[Scope]
    protected function successful(Builder $query): Builder
    {
        return $query->where('status', DeliveryStatus::Done->value);
    }

    #[Scope]
    protected function failed(Builder $query): Builder
    {
        return $query->whereIn('status', [
            DeliveryStatus::Failed->value,
            DeliveryStatus::Dlq->value,
        ]);
    }

    #[Scope]
    protected function dlq(Builder $query): Builder
    {
        return $query->where('status', DeliveryStatus::Dlq->value);
    }

    #[Scope]
    protected function retryable(Builder $query): Builder
    {
        return $query->whereIn('status', [
            DeliveryStatus::Failed->value,
            DeliveryStatus::Retrying->value,
        ]);
    }

    #[Scope]
    protected function forWorkflow(Builder $query, int $workflowId): Builder
    {
        return $query->where('workflow_id', $workflowId);
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(N8nWorkflow::class, 'workflow_id');
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(N8nEndpoint::class, 'endpoint_id');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function markProcessed(int $durationMs): void
    {
        $this->update([
            'status'       => DeliveryStatus::Done,
            'duration_ms'  => $durationMs,
            'processed_at' => now(),
        ]);
    }

    public function markFailed(string $message, string $errorClass, int $durationMs): void
    {
        $this->increment('attempts');

        $status = $this->attempts >= $this->maxAttempts()
            ? DeliveryStatus::Dlq
            : DeliveryStatus::Failed;

        $this->update([
            'status'        => $status,
            'error_message' => $message,
            'error_class'   => $errorClass,
            'duration_ms'   => $durationMs,
        ]);
    }

    public function markSkipped(): void
    {
        $this->update(['status' => DeliveryStatus::Skipped]);
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }

    private function maxAttempts(): int
    {
        return $this->endpoint?->max_attempts ?? 3;
    }

    protected function getTableBaseName(): string
    {
        return 'deliveries__lists';
    }

    protected static function newFactory(): N8nDeliveryFactory
    {
        return N8nDeliveryFactory::new();
    }
}
