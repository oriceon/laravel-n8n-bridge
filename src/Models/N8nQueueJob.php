<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\ObservedBy;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Facades\Log;
use Oriceon\N8nBridge\Concerns\HasDynamicTable;
use Oriceon\N8nBridge\Concerns\HasPublicUuid;
use Oriceon\N8nBridge\Database\Factories\N8nQueueJobFactory;
use Oriceon\N8nBridge\Enums\QueueFailureReason;
use Oriceon\N8nBridge\Enums\QueueJobPriority;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Observers\N8nQueueJobObserver;

/**
 * A single queued n8n workflow dispatch job.
 *
 * DB table: {prefix}__queue__jobs
 *
 * Key design decisions:
 * - SELECT FOR UPDATE SKIP LOCKED for atomic worker claiming
 * - priority DESC + available_at ASC ordering guarantees fairness
 * - worker_id tracks which process owns the row
 * - reserved_until prevents orphaned claimed jobs
 *
 * @property int $id
 * @property int|null $workflow_id
 * @property int|null $batch_id
 * @property QueueJobPriority $priority
 * @property QueueJobStatus $status
 * @property array $payload
 * @property array|null $context Extra metadata (source model, user, etc.)
 * @property string|null $n8n_instance
 * @property int $attempts
 * @property int $max_attempts
 * @property int $timeout_seconds
 * @property QueueFailureReason|null $last_failure_reason
 * @property string|null $last_error_message
 * @property string|null $last_error_class
 * @property string|null $worker_id
 * @property Carbon|null $available_at Null = process immediately
 * @property Carbon|null $reserved_until Worker lease expiry
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 * @property int|null $duration_ms
 * @property int|null $n8n_execution_id ID returned by n8n after trigger
 * @property array|null $n8n_response
 * @property string|null $queue_name
 * @property string|null $idempotency_key
 */
#[Fillable([
    'uuid',
    'workflow_id',
    'batch_id',
    'priority',
    'status',
    'payload',
    'context',
    'n8n_instance',
    'attempts',
    'max_attempts',
    'timeout_seconds',
    'last_failure_reason',
    'last_error_message',
    'last_error_class',
    'worker_id',
    'available_at',
    'reserved_until',
    'started_at',
    'finished_at',
    'duration_ms',
    'n8n_execution_id',
    'n8n_response',
    'queue_name',
    'idempotency_key',
    'updated_at',
    'created_at',
])]
#[ObservedBy([N8nQueueJobObserver::class])]
class N8nQueueJob extends Model
{
    use HasDynamicTable;

    /** @use HasFactory<N8nQueueJobFactory> */
    use HasFactory;

    use HasPublicUuid;

    protected function casts(): array
    {
        return [
            'priority'            => QueueJobPriority::class,
            'status'              => QueueJobStatus::class,
            'payload'             => 'array',
            'context'             => 'array',
            'last_failure_reason' => QueueFailureReason::class,
            'n8n_response'        => 'array',
            'available_at'        => 'datetime',
            'reserved_until'      => 'datetime',
            'started_at'          => 'datetime',
            'finished_at'         => 'datetime',
            'n8n_execution_id'    => 'integer',
            'attempts'            => 'integer',
            'max_attempts'        => 'integer',
            'timeout_seconds'     => 'integer',
            'duration_ms'         => 'integer',
        ];
    }

    protected $attributes = [
        'status'          => 'pending',
        'attempts'        => 0,
        'max_attempts'    => 3,
        'timeout_seconds' => 120,
        'n8n_instance'    => 'default',
        'queue_name'      => 'default',
    ];

    // ── Scopes ─────────────────────────────────────────────────────────────────

    /**
     * Ready to be claimed: pending + available_at in the past.
     * Ordered by priority DESC then available_at ASC (the oldest high priority first).
     */
    #[Scope]
    protected function available(Builder $q): Builder
    {
        return $q
            ->where('status', QueueJobStatus::Pending->value)
            ->where(
                fn(Builder $q) => $q
                    ->whereNull('available_at')
                    ->orWhere('available_at', '<=', now())
            )
            ->orderByDesc('priority')
            ->orderBy('available_at');
    }

    #[Scope]
    protected function forQueue(Builder $q, string $name): Builder
    {
        return $q->where('queue_name', $name);
    }

    #[Scope]
    protected function forWorkflow(Builder $q, int $workflowId): Builder
    {
        return $q->where('workflow_id', $workflowId);
    }

    #[Scope]
    protected function forBatch(Builder $q, string $batchId): Builder
    {
        return $q->where('batch_id', $batchId);
    }

    #[Scope]
    protected function pending(Builder $q): Builder
    {
        return $q->where('status', QueueJobStatus::Pending->value);
    }

    #[Scope]
    protected function active(Builder $q): Builder
    {
        return $q->whereIn('status', [
            QueueJobStatus::Claimed->value,
            QueueJobStatus::Running->value,
        ]);
    }

    #[Scope]
    protected function deadLetters(Builder $q): Builder
    {
        return $q->where('status', QueueJobStatus::Dead->value);
    }

    #[Scope]
    protected function stuck(Builder $q, int $minutes = 10): Builder
    {
        // Jobs claimed but reserved_until expired — likely orphaned workers
        return $q
            ->whereIn('status', [QueueJobStatus::Claimed->value, QueueJobStatus::Running->value])
            ->where('reserved_until', '<', now()->subMinutes($minutes));
    }

    #[Scope]
    protected function byPriority(Builder $q, QueueJobPriority $priority): Builder
    {
        return $q->where('priority', $priority->value);
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(N8nWorkflow::class, 'workflow_id');
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(N8nQueueBatch::class, 'batch_id');
    }

    public function failures(): HasMany
    {
        return $this->hasMany(N8nQueueFailure::class, 'job_id');
    }

    public function checkpoints(): HasMany
    {
        return $this->hasMany(N8nQueueCheckpoint::class, 'job_id')->orderBy('sequence');
    }

    public function latestCheckpoint(): HasOne
    {
        return $this->hasOne(N8nQueueCheckpoint::class, 'job_id')->latestOfMany('sequence');
    }

    // ── State transitions ─────────────────────────────────────────────────────

    /**
     * Atomically claim this job for a worker.
     * Returns false if already claimed by another worker.
     */
    public function claim(string $workerId, int $leaseSec = 120): bool
    {
        $updated = static::query()
            ->where('id', $this->id)
            ->where('status', QueueJobStatus::Pending->value)
            ->update([
                'status'         => QueueJobStatus::Claimed->value,
                'worker_id'      => $workerId,
                'reserved_until' => now()->addSeconds($leaseSec),
                'started_at'     => now(),
                'attempts'       => $this->attempts + 1,
            ]);

        if ($updated) {
            $this->refresh();
        }

        return (bool) $updated;
    }

    public function markRunning(): void
    {
        $this->update(['status' => QueueJobStatus::Running->value]);
    }

    public function markDone(array $n8nResponse = []): void
    {
        $executionId = null;

        if (isset($n8nResponse['execution_id'])) {
            if (is_numeric($n8nResponse['execution_id'])) {
                $executionId = (int) $n8nResponse['execution_id'];
            }
            else {
                Log::warning('n8n-bridge: non-numeric execution_id in response', [
                    'job_id'       => $this->id,
                    'execution_id' => $n8nResponse['execution_id'],
                ]);
            }

            unset($n8nResponse['execution_id']);
        }

        $now = now();
        $this->update([
            'status'      => QueueJobStatus::Done->value,
            'finished_at' => $now,
            'duration_ms' => $this->started_at
                ? (int) $this->started_at->diffInMilliseconds($now)
                : null,
            'n8n_execution_id' => $executionId ?: null,
            'n8n_response'     => $n8nResponse ?: null,
        ]);
    }

    public function markFailed(
        QueueFailureReason $reason,
        string $errorMessage,
        string $errorClass,
        int $delaySeconds = 0,
    ): void {
        $exhausted = $this->attempts >= $this->max_attempts;

        $this->update([
            'status' => $exhausted
                ? QueueJobStatus::Dead->value
                : QueueJobStatus::Failed->value,
            'last_failure_reason' => $reason->value,
            'last_error_message'  => $errorMessage,
            'last_error_class'    => $errorClass,
            'available_at'        => $exhausted
                ? null
                : now()->addSeconds(max($delaySeconds, $reason->suggestedDelaySeconds())),
        ]);

        // If retryable, flip back to pending for the next pick-up
        if ( ! $exhausted && $reason->isRetryable()) {
            $this->update(['status' => QueueJobStatus::Pending->value]);
        }
    }

    public function cancel(string $reason = ''): void
    {
        $this->update([
            'status'             => QueueJobStatus::Cancelled->value,
            'last_error_message' => $reason ?: 'Manually cancelled',
            'worker_id'          => null,
            'reserved_until'     => null,
        ]);
    }

    public function reschedule(\DateTimeInterface|Carbon $at): void
    {
        $this->update([
            'status'       => QueueJobStatus::Pending->value,
            'available_at' => $at,
            'worker_id'    => null,
        ]);
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    public function isExhausted(): bool
    {
        return $this->attempts >= $this->max_attempts;
    }

    public function isStuck(int $minutes = 10): bool
    {
        return $this->status->isActive() &&
            $this->reserved_until !== null &&
            $this->reserved_until->isPast() &&
            $minutes <= $this->reserved_until->diffInMinutes(now());
    }

    public function isDue(): bool
    {
        return $this->available_at === null || $this->available_at->isPast();
    }

    /**
     * Estimated progress percentage based on elapsed time vs. workflow's EMA.
     *
     * Returns null when:
     *   - Job hasn't started yet
     *   - No duration estimate available for the workflow
     *   - Job is already in a terminal state (use 100 or 0 directly)
     *
     * Returns 0–99 while running (never 100 — markDone() triggers that).
     * Once Done, use 100 directly.
     */
    public function estimatedProgressPercent(): ?int
    {
        if ($this->status === QueueJobStatus::Done) {
            return 100;
        }

        if ($this->status->isTerminal()) {
            return null; // Failed / Dead / Cancelled — no meaningful progress
        }

        if ($this->started_at === null) {
            return 0;
        }

        $estimated = $this->workflow?->estimated_duration_ms;

        if ( ! $estimated || $estimated <= 0) {
            return null; // No estimate available yet
        }

        $elapsedMs = (int) $this->started_at->diffInMilliseconds(now());
        $percent   = (int) round(($elapsedMs / $estimated) * 100);

        // Cap at 99 — only markDone() reaches 100
        return min(99, max(0, $percent));
    }

    /**
     * Overall progress: 0-100, derived from checkpoints.
     * Returns null if no checkpoints yet.
     */
    public function progressPercent(): ?int
    {
        if ($this->status === QueueJobStatus::Done) {
            return 100;
        }

        $latest = $this->checkpoints()->orderByDesc('sequence')->first();

        if ($latest === null) {
            return null;
        }

        return $latest->progress_percent
            ?? (int) round(
                $this->checkpoints()->whereIn('status', ['completed', 'skipped'])->count()
                / max(1, $this->checkpoints()->count()) * 100
            );
    }

    protected function getTableBaseName(): string
    {
        return 'queue__jobs';
    }

    protected static function newFactory(): N8nQueueJobFactory
    {
        return N8nQueueJobFactory::new();
    }
}
