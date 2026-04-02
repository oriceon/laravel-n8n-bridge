<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Oriceon\N8nBridge\Concerns\HasDynamicTable;
use Oriceon\N8nBridge\Concerns\HasPublicUuid;
use Oriceon\N8nBridge\Enums\DeliveryDirection;
use Oriceon\N8nBridge\Enums\StatPeriod;

/**
 * Pre-aggregated statistics per workflow/period.
 *
 * @property int               $id
 * @property int               $workflow_id
 * @property int|null          $endpoint_id
 * @property DeliveryDirection $direction
 * @property StatPeriod        $period
 * @property \Carbon\Carbon    $period_date
 * @property int               $total_count
 * @property int               $success_count
 * @property int               $failed_count
 * @property int               $dlq_count
 * @property int               $skipped_count
 * @property int               $avg_duration_ms
 * @property int               $p95_duration_ms
 * @property int               $total_bytes_in
 * @property int               $total_bytes_out
 */
#[Fillable([
    'uuid',
    'workflow_id',
    'endpoint_id',
    'direction',
    'period',
    'period_date',
    'total_count',
    'success_count',
    'failed_count',
    'dlq_count',
    'skipped_count',
    'avg_duration_ms',
    'p95_duration_ms',
    'total_bytes_in',
    'total_bytes_out',
])]
class N8nStat extends Model
{
    use HasDynamicTable;
    use HasPublicUuid;

    protected function casts(): array
    {
        return [
            'direction' => DeliveryDirection::class,
            'period'    => StatPeriod::class,
            // period_date handled by periodDate() Attribute (stored as Y-m-d string)
            'total_count'     => 'integer',
            'success_count'   => 'integer',
            'failed_count'    => 'integer',
            'dlq_count'       => 'integer',
            'skipped_count'   => 'integer',
            'avg_duration_ms' => 'integer',
            'p95_duration_ms' => 'integer',
            'total_bytes_in'  => 'integer',
            'total_bytes_out' => 'integer',
        ];
    }

    // ── Attributes ────────────────────────────────────────────────────────────

    /**
     * Store period_date as Y-m-d string to avoid SQLite Y-m-d H:i:s storage
     * from the default 'date' cast, which breaks exact WHERE comparisons.
     */
    protected function periodDate(): Attribute
    {
        return Attribute::make(
            get: static fn($value) => $value ? Carbon::parse($value) : null,
            set: static fn($value) => Carbon::parse($value)->toDateString(),
        );
    }

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * @param Builder $query
     * @param StatPeriod $period
     * @return Builder
     */
    #[Scope]
    protected function forPeriod(Builder $query, StatPeriod $period): Builder
    {
        return $query->where('period', $period->value);
    }

    /**
     * @param Builder $query
     * @param int $days
     * @return Builder
     */
    #[Scope]
    protected function lastDays(Builder $query, int $days): Builder
    {
        return $query->whereDate('period_date', '>=', now()->subDays($days)->toDateString());
    }

    // ── Relations ────────────────────────────────────────────────────────────────

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(N8nWorkflow::class, 'workflow_id');
    }

    public function endpoint(): BelongsTo
    {
        return $this->belongsTo(N8nEndpoint::class, 'endpoint_id');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    public function successRate(): float
    {
        if ($this->total_count === 0) {
            return 0.0;
        }

        return round(($this->success_count / $this->total_count) * 100, 2);
    }

    public function failureRate(): float
    {
        if ($this->total_count === 0) {
            return 0.0;
        }

        return round((($this->failed_count + $this->dlq_count) / $this->total_count) * 100, 2);
    }

    protected function getTableBaseName(): string
    {
        return 'stats__lists';
    }
}
