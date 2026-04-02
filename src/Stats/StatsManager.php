<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Stats;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Oriceon\N8nBridge\Enums\DeliveryDirection;
use Oriceon\N8nBridge\Enums\DeliveryStatus;
use Oriceon\N8nBridge\Enums\StatPeriod;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nStat;
use Oriceon\N8nBridge\Models\N8nWorkflow;

/**
 * Query builder for bridge statistics.
 *
 * Usage:
 *   app(StatsManager::class)->overview()
 *   app(StatsManager::class)->forWorkflow($wf)->lastDays(30)->get()
 */
final class StatsManager
{
    private int|string|null $workflowId = null;

    private int|string|null $endpointId = null;

    private ?StatPeriod $period = null;

    private ?string $startDate  = null;

    private ?string $endDate    = null;

    private ?DeliveryDirection $direction = null;

    /**
     * @param N8nWorkflow|string $workflow
     * @return $this
     */
    public function forWorkflow(N8nWorkflow|string $workflow): self
    {
        return clone($this, [
            'workflowId' => $workflow instanceof N8nWorkflow
                ? $workflow->id
                : $workflow,
        ]);
    }

    /**
     * @param string $endpointId
     * @return $this
     */
    public function forEndpoint(string $endpointId): self
    {
        return clone($this, [
            'endpointId' => $endpointId,
        ]);
    }

    /**
     * @param StatPeriod $period
     * @return $this
     */
    public function period(StatPeriod $period): self
    {
        return clone($this, [
            'period' => $period,
        ]);
    }

    /**
     * @param DeliveryDirection $direction
     * @return $this
     */
    public function direction(DeliveryDirection $direction): self
    {
        return clone($this, [
            'direction' => $direction,
        ]);
    }

    /**
     * @param int $days
     * @return $this
     */
    public function lastDays(int $days): self
    {
        return clone($this, [
            'startDate' => now()->subDays($days)->toDateString(),
            'endDate'   => now()->toDateString(),
        ]);
    }

    /**
     * @param string $start
     * @param string $end
     * @return $this
     */
    public function between(string $start, string $end): self
    {
        return clone($this, [
            'startDate' => $start,
            'endDate'   => $end,
        ]);
    }

    public function get(): Collection
    {
        return $this->buildQuery()->get();
    }

    /** Global overview — stats across all workflows. */
    public function overview(): array
    {
        $total   = N8nDelivery::query()->count();
        $success = N8nDelivery::query()->where('status', DeliveryStatus::Done->value)->count();
        $failed  = N8nDelivery::query()
            ->whereIn('status', [DeliveryStatus::Failed->value, DeliveryStatus::Dlq->value])
            ->count();
        $dlq     = N8nDelivery::query()->where('status', DeliveryStatus::Dlq->value)->count();

        $avgMs = N8nDelivery::query()
            ->where('status', DeliveryStatus::Done->value)
            ->whereNotNull('duration_ms')
            ->avg('duration_ms');

        return [
            'total_deliveries' => $total,
            'success_count'    => $success,
            'failed_count'     => $failed,
            'dlq_pending'      => $dlq,
            'success_rate'     => $total > 0 ? round($success / $total * 100, 2) : 0.0,
            'avg_duration_ms'  => round((float) $avgMs, 2),
            'failed_today'     => N8nDelivery::query()
                ->whereIn('status', [DeliveryStatus::Failed->value, DeliveryStatus::Dlq->value])
                ->whereDate('created_at', today())
                ->count(),
        ];
    }

    /** Returns chart-ready data: ['labels' => [...dates], 'success' => [...], 'failed' => [...]] */
    public function toChartData(): array
    {
        $rows = $this->get();

        return [
            'labels'       => $rows->pluck('period_date')->map(fn($d) => (string) $d)->toArray(),
            'success'      => $rows->pluck('success_count')->toArray(),
            'failed'       => $rows->pluck('failed_count')->toArray(),
            'dlq'          => $rows->pluck('dlq_count')->toArray(),
            'success_rate' => $rows->map(fn($r) => $r->successRate())->toArray(),
            'avg_ms'       => $rows->pluck('avg_duration_ms')->toArray(),
        ];
    }

    /** Top workflows by failure count. */
    public function topFailed(int $limit = 10): Collection
    {
        return N8nDelivery::query()
            ->select('workflow_id')
            ->selectRaw('COUNT(*) as failed_count')
            ->whereIn('status', [DeliveryStatus::Failed->value, DeliveryStatus::Dlq->value])
            ->groupBy('workflow_id')
            ->orderByDesc('failed_count')
            ->limit($limit)
            ->with('workflow:id,name')
            ->get();
    }

    private function buildQuery(): Builder
    {
        $query = N8nStat::query();

        if ($this->workflowId !== null) {
            $query->where('workflow_id', $this->workflowId);
        }

        if ($this->endpointId !== null) {
            $query->where('endpoint_id', $this->endpointId);
        }

        if ($this->period !== null) {
            $query->where('period', $this->period->value);
        }

        if ($this->direction !== null) {
            $query->where('direction', $this->direction->value);
        }

        if ($this->startDate !== null) {
            $query->whereDate('period_date', '>=', $this->startDate);
        }

        if ($this->endDate !== null) {
            $query->whereDate('period_date', '<=', $this->endDate);
        }

        return $query->orderBy('period_date');
    }
}
