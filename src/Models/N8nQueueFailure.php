<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Oriceon\N8nBridge\Concerns\HasDynamicTable;
use Oriceon\N8nBridge\Concerns\HasPublicUuid;
use Oriceon\N8nBridge\Enums\QueueFailureReason;

/**
 * Immutable record of every individual failure attempt.
 *
 * Each attempt that fails appends a new row here.
 * When a job goes Dead, ALL its failure attempts are preserved.
 * This gives a full audit trail for debugging.
 *
 * @property int                   $id
 * @property int                   $job_id
 * @property int                   $workflow_id
 * @property int                   $attempt_number
 * @property QueueFailureReason    $reason
 * @property string                $error_message
 * @property string|null           $error_class
 * @property string|null           $stack_trace
 * @property int|null              $http_status
 * @property array|null            $http_response
 * @property string|null           $worker_id
 * @property int|null              $duration_ms
 * @property array                 $payload_snapshot  Payload at the time of failure
 * @property array|null            $context
 * @property bool                  $was_retried
 * @property bool                  $was_replayed      Replayed manually after going dead
 */
#[Fillable([
    'uuid',
    'job_id',
    'workflow_id',
    'attempt_number',
    'reason',
    'error_message',
    'error_class',
    'stack_trace',
    'http_status',
    'http_response',
    'worker_id',
    'duration_ms',
    'payload_snapshot',
    'context',
    'was_retried',
    'was_replayed',
])]
#[Hidden(['stack_trace'])]
class N8nQueueFailure extends Model
{
    use HasDynamicTable;
    use HasPublicUuid;

    public const null UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'reason'           => QueueFailureReason::class,
            'http_response'    => 'array',
            'payload_snapshot' => 'array',
            'context'          => 'array',
            'attempt_number'   => 'integer',
            'http_status'      => 'integer',
            'duration_ms'      => 'integer',
            'was_retried'      => 'boolean',
            'was_replayed'     => 'boolean',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * @param Builder $q
     * @param QueueFailureReason $reason
     * @return Builder
     */
    #[Scope]
    protected function forReason(Builder $q, QueueFailureReason $reason): Builder
    {
        return $q->where('reason', $reason->value);
    }

    /**
     * @param Builder $q
     * @return Builder
     */
    #[Scope]
    protected function retryable(Builder $q): Builder
    {
        $values = collect(QueueFailureReason::cases())
            ->filter(fn($r) => $r->isRetryable())
            ->map(fn($r) => $r->value)
            ->all();

        return $q->whereIn('reason', $values);
    }

    /**
     * @param Builder $q
     * @return Builder
     */
    #[Scope]
    protected function notReplayed(Builder $q): Builder
    {
        return $q->where('was_replayed', false);
    }

    /**
     * @param Builder $q
     * @param int $workflowId
     * @return Builder
     */
    #[Scope]
    protected function forWorkflow(Builder $q, int $workflowId): Builder
    {
        return $q->where('workflow_id', $workflowId);
    }

    /**
     * @param Builder $q
     * @param int $hours
     * @return Builder
     */
    #[Scope]
    protected function lastHours(Builder $q, int $hours = 24): Builder
    {
        return $q->where('created_at', '>=', now()->subHours($hours));
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function job(): BelongsTo
    {
        return $this->belongsTo(N8nQueueJob::class, 'job_id');
    }

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(N8nWorkflow::class, 'workflow_id');
    }

    protected function getTableBaseName(): string
    {
        return 'queue__failures';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * @param N8nQueueJob $job
     * @param QueueFailureReason $reason
     * @param string $errorMessage
     * @param string $errorClass
     * @param int $httpStatus
     * @param array $httpResponse
     * @param int $durationMs
     * @param string|null $stackTrace
     * @return self
     */
    public static function recordFromJob(
        N8nQueueJob $job,
        QueueFailureReason $reason,
        string $errorMessage,
        string $errorClass = '',
        int $httpStatus = 0,
        array $httpResponse = [],
        int $durationMs = 0,
        ?string $stackTrace = null,
    ): self {
        return self::create([
            'job_id'           => $job->id,
            'workflow_id'      => $job->workflow_id,
            'attempt_number'   => $job->attempts,
            'reason'           => $reason->value,
            'error_message'    => $errorMessage,
            'error_class'      => $errorClass ?: null,
            'stack_trace'      => $stackTrace,
            'http_status'      => $httpStatus ?: null,
            'http_response'    => $httpResponse ?: null,
            'worker_id'        => $job->worker_id,
            'duration_ms'      => $durationMs ?: null,
            'payload_snapshot' => $job->payload,
            'context'          => $job->context,
            'was_retried'      => $job->attempts < $job->max_attempts,
            'was_replayed'     => false,
        ]);
    }
}
