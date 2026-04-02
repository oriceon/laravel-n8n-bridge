<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Oriceon\N8nBridge\Concerns\HasDynamicTable;
use Oriceon\N8nBridge\Concerns\HasPublicUuid;
use Oriceon\N8nBridge\Enums\QueueJobPriority;
use Oriceon\N8nBridge\Enums\QueueJobStatus;

/**
 * Groups many queue jobs into a single batch operation.
 *
 * Example use cases:
 *   - Bulk-trigger 5,000 "invoice-reminder" workflows
 *   - Nightly sync of all contacts
 *   - Fan-out a single event to many workflow variants
 *
 * @property int    $id
 * @property string              $name
 * @property string|null         $description
 * @property QueueJobPriority    $priority
 * @property int                 $total_jobs
 * @property int                 $pending_jobs
 * @property int                 $done_jobs
 * @property int                 $failed_jobs
 * @property int                 $dead_jobs
 * @property int                 $cancelled_jobs
 * @property bool                $cancelled
 * @property array|null          $options
 * @property \Carbon\Carbon|null $started_at
 * @property \Carbon\Carbon|null $finished_at
 */
#[Fillable([
    'uuid',
    'name',
    'description',
    'priority',
    'total_jobs',
    'pending_jobs',
    'done_jobs',
    'failed_jobs',
    'dead_jobs',
    'cancelled_jobs',
    'cancelled',
    'options',
    'started_at',
    'finished_at',
])]
class N8nQueueBatch extends Model
{
    use HasDynamicTable;
    use HasPublicUuid;

    protected function casts(): array
    {
        return [
            'priority'       => QueueJobPriority::class,
            'cancelled'      => 'boolean',
            'options'        => 'array',
            'started_at'     => 'datetime',
            'finished_at'    => 'datetime',
            'total_jobs'     => 'integer',
            'pending_jobs'   => 'integer',
            'done_jobs'      => 'integer',
            'failed_jobs'    => 'integer',
            'dead_jobs'      => 'integer',
            'cancelled_jobs' => 'integer',
        ];
    }

    protected $attributes = [
        'priority'       => 50, // Normal
        'total_jobs'     => 0,
        'pending_jobs'   => 0,
        'done_jobs'      => 0,
        'failed_jobs'    => 0,
        'dead_jobs'      => 0,
        'cancelled_jobs' => 0,
        'cancelled'      => false,
    ];

    // ── Relations ─────────────────────────────────────────────────────────────

    public function jobs(): HasMany
    {
        return $this->hasMany(N8nQueueJob::class, 'batch_id');
    }

    // ── Helpers ─────────────────────────────────────────────────────────────

    public function progressPercent(): float
    {
        if ($this->total_jobs === 0) {
            return 0.0;
        }

        return round(
            ($this->done_jobs + $this->dead_jobs + $this->cancelled_jobs) / $this->total_jobs * 100,
            1
        );
    }

    public function successRate(): float
    {
        $processed = $this->done_jobs + $this->dead_jobs;

        if ($processed === 0) {
            return 0.0;
        }

        return round($this->done_jobs / $processed * 100, 1);
    }

    public function isComplete(): bool
    {
        return $this->total_jobs > 0 &&
            ($this->done_jobs + $this->dead_jobs + $this->cancelled_jobs) >= $this->total_jobs;
    }

    public function isPending(): bool
    {
        return $this->pending_jobs > 0 && ! $this->cancelled;
    }

    public function cancel(): void
    {
        $this->update(['cancelled' => true]);

        // Cancel all pending jobs in this batch
        N8nQueueJob::query()
            ->forBatch($this->id)
            ->pending()
            ->update([
                'status'             => QueueJobStatus::Cancelled->value,
                'last_error_message' => 'Batch cancelled',
            ]);

        $this->recalculate();
    }

    public function recalculate(): void
    {
        $jobs = $this->jobs();

        $pendingJobs   = (clone $jobs)->pending()->count();
        $doneJobs      = (clone $jobs)->where('status', QueueJobStatus::Done->value)->count();
        $failedJobs    = (clone $jobs)->where('status', QueueJobStatus::Failed->value)->count();
        $deadJobs      = (clone $jobs)->where('status', QueueJobStatus::Dead->value)->count();
        $cancelledJobs = (clone $jobs)->where('status', QueueJobStatus::Cancelled->value)->count();

        $isComplete = $this->total_jobs > 0 &&
            ($doneJobs + $deadJobs + $cancelledJobs) >= $this->total_jobs;

        $this->update([
            'pending_jobs'   => $pendingJobs,
            'done_jobs'      => $doneJobs,
            'failed_jobs'    => $failedJobs,
            'dead_jobs'      => $deadJobs,
            'cancelled_jobs' => $cancelledJobs,
            'finished_at'    => $isComplete ? now() : null,
        ]);
    }

    protected function getTableBaseName(): string
    {
        return 'queue__batches';
    }
}
