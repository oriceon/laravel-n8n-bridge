<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Oriceon\N8nBridge\Concerns\HasDynamicTable;
use Oriceon\N8nBridge\Concerns\HasPublicUuid;
use Oriceon\N8nBridge\Enums\CheckpointStatus;

/**
 * A single progress checkpoint sent by an n8n node during workflow execution.
 *
 * n8n sends these via POST /n8n/queue/progress/{jobId} at any point
 * during the workflow run. Each checkpoint is append-only and immutable.
 *
 * Usage in n8n (HTTP Request node after each significant node):
 *   URL: POST https://myapp.com/n8n/queue/progress/{{ $json.job_id }}
 *   Body: {
 *     "node": "send_invoice_email",
 *     "status": "completed",
 *     "message": "Email sent to john@example.com",
 *     "data": { "email_id": "msg_123", "recipient": "john@example.com" }
 *   }
 *
 * @property int                     $id
 * @property int                     $job_id
 * @property string                  $node_name        n8n node name/key
 * @property string|null             $node_label       Human-readable label
 * @property CheckpointStatus        $status
 * @property string|null             $message          Free-text description
 * @property array|null              $data             Any structured data from the node
 * @property string|null             $error_message    Set when status=failed
 * @property int|null                $progress_percent 0-100 (optional, set by n8n)
 * @property int                     $sequence         Auto-incrementing order
 * @property \Carbon\Carbon          $created_at
 */
#[Fillable([
    'uuid',
    'job_id',
    'node_name',
    'node_label',
    'status',
    'message',
    'data',
    'error_message',
    'progress_percent',
    'sequence',
])]
class N8nQueueCheckpoint extends Model
{
    use HasDynamicTable;
    use HasPublicUuid;

    public const null UPDATED_AT = null; // Append-only

    protected function casts(): array
    {
        return [
            'status'           => CheckpointStatus::class,
            'data'             => 'array',
            'progress_percent' => 'integer',
            'sequence'         => 'integer',
        ];
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * @param Builder $q
     * @param int|string $jobId
     * @return Builder
     */
    #[Scope]
    protected function forJob(Builder $q, int|string $jobId): Builder
    {
        return $q->where('job_id', $jobId)->orderBy('sequence');
    }

    /**
     * @param Builder $q
     * @return Builder
     */
    #[Scope]
    protected function failed(Builder $q): Builder
    {
        return $q->where('status', CheckpointStatus::Failed->value);
    }

    /**
     * @param Builder $q
     * @return Builder
     */
    #[Scope]
    protected function completed(Builder $q): Builder
    {
        return $q->where('status', CheckpointStatus::Completed->value);
    }

    // ── Relations ─────────────────────────────────────────────────────────────

    public function job(): BelongsTo
    {
        return $this->belongsTo(N8nQueueJob::class, 'job_id');
    }

    protected function getTableBaseName(): string
    {
        return 'queue__checkpoints';
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Build a structured timeline from all checkpoints for a job.
     *
     * Returns array of:
     * [
     *   ['node' => 'fetch_invoice', 'label' => 'Fetch Invoice', 'status' => 'completed',
     *    'message' => '...', 'data' => [...], 'at' => Carbon],
     *   ...
     * ]
     */
    public static function timelineForJob(int|string $jobId): array
    {
        return static::query()
            ->forJob($jobId)
            ->get()
            ->map(fn(self $cp) => [
                'id'       => $cp->id,
                'node'     => $cp->node_name,
                'label'    => $cp->node_label ?? $cp->node_name,
                'status'   => $cp->status->value,
                'color'    => $cp->status->color(),
                'icon'     => $cp->status->icon(),
                'message'  => $cp->message,
                'data'     => $cp->data,
                'error'    => $cp->error_message,
                'progress' => $cp->progress_percent,
                'sequence' => $cp->sequence,
                'at'       => $cp->created_at->toIso8601String(),
            ])
            ->all();
    }

    public function isTerminal(): bool
    {
        return $this->status->isTerminal();
    }
}
