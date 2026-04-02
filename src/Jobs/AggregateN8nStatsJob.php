<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\Attributes\Timeout;
use Illuminate\Queue\Attributes\Tries;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Oriceon\N8nBridge\Enums\DeliveryDirection;
use Oriceon\N8nBridge\Enums\DeliveryStatus;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Enums\StatPeriod;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nQueueJob;
use Oriceon\N8nBridge\Models\N8nStat;
use Oriceon\N8nBridge\Models\N8nWorkflow;
use Oriceon\N8nBridge\Notifications\NotificationDispatcher;

/**
 * Aggregates delivery data into n8n__stats for the previous day.
 *
 * Scheduled daily. Also checks error rate thresholds for notifications.
 */
#[Tries(1)]
#[Timeout(120)]
final class AggregateN8nStatsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        private readonly string $date, // 'Y-m-d' — date to aggregate
    ) {
    }

    public function handle(NotificationDispatcher $notifier): void
    {
        $workflows = N8nWorkflow::query()->active()->get();

        foreach ($workflows as $workflow) {
            $this->aggregateWorkflow($workflow, $notifier);
        }

        $this->aggregateQueueJobs($notifier);
    }

    /**
     * Aggregates queue job data into n8n__stats__lists with direction='queue'.
     *
     * Groups jobs by workflow_id for the given date (based on created_at).
     * Counts:
     *   - total_count   = all jobs created that day
     *   - success_count = done jobs
     *   - failed_count  = dead + failed jobs (any failed state)
     *   - dlq_count     = dead jobs (exhausted all retries)
     *   - skipped_count = cancelled jobs
     *
     * Duration metrics are derived from done jobs only.
     */
    private function aggregateQueueJobs(NotificationDispatcher $notifier): void
    {
        $jobs = N8nQueueJob::query()
            ->whereDate('created_at', $this->date)
            ->get();

        if ($jobs->isEmpty()) {
            return;
        }

        foreach ($jobs->groupBy('workflow_id') as $workflowId => $group) {
            $total     = $group->count();
            $success   = $group->where('status', QueueJobStatus::Done->value)->count();
            $dead      = $group->where('status', QueueJobStatus::Dead->value)->count();
            $cancelled = $group->where('status', QueueJobStatus::Cancelled->value)->count();
            // failed_count covers all non-terminal failures: retrying + permanently dead
            $failed = $group->where('status', QueueJobStatus::Failed->value)->count() + $dead;

            $durations = $group
                ->where('status', QueueJobStatus::Done->value)
                ->whereNotNull('duration_ms')
                ->pluck('duration_ms')
                ->sort()
                ->values();

            $avgMs = $durations->isEmpty() ? 0 : (int) $durations->avg();
            $p95Ms = $durations->isEmpty() ? 0
                : (int) ($durations->get((int) round($durations->count() * 0.95)) ?? $durations->last() ?? 0);

            N8nStat::updateOrCreate(
                [
                    'workflow_id' => $workflowId,
                    'endpoint_id' => null,
                    'direction'   => DeliveryDirection::Queue->value,
                    'period'      => StatPeriod::Daily->value,
                    'period_date' => $this->date,
                ],
                [
                    'total_count'     => $total,
                    'success_count'   => $success,
                    'failed_count'    => $failed,
                    'dlq_count'       => $dead,
                    'skipped_count'   => $cancelled,
                    'avg_duration_ms' => $avgMs,
                    'p95_duration_ms' => $p95Ms,
                    'total_bytes_in'  => 0,
                    'total_bytes_out' => 0,
                ]
            );

            // Notify on high dead-job rate (dead = exhausted all retries)
            $errorRate = $total > 0 ? ($dead / $total) * 100 : 0.0;
            $workflow  = N8nWorkflow::find($workflowId);

            if ($workflow !== null) {
                $notifier->notifyHighErrorRate($workflow, round($errorRate, 2), $this->date);
            }
        }
    }

    private function aggregateWorkflow(
        N8nWorkflow $workflow,
        NotificationDispatcher $notifier,
    ): void {
        $deliveries = N8nDelivery::query()
            ->where('workflow_id', $workflow->id)
            ->whereDate('created_at', $this->date)
            ->get();

        if ($deliveries->isEmpty()) {
            return;
        }

        // Group by direction
        foreach ($deliveries->groupBy('direction') as $direction => $group) {
            $total   = $group->count();
            $success = $group->where('status', DeliveryStatus::Done->value)->count();
            $failed  = $group->where('status', DeliveryStatus::Failed->value)->count();
            $dlq     = $group->where('status', DeliveryStatus::Dlq->value)->count();
            $skipped = $group->where('status', DeliveryStatus::Skipped->value)->count();

            // p95 duration — sort, take 95th percentile
            $durations = $group
                ->whereNotNull('duration_ms')
                ->pluck('duration_ms')
                ->sort()
                ->values();

            $avgMs = $durations->isEmpty() ? 0 : (int) $durations->avg();
            $p95Ms = $durations->isEmpty() ? 0 : (int) ($durations->get((int) round($durations->count() * 0.95)) ?? 0);

            // Upsert stat record
            N8nStat::updateOrCreate(
                [
                    'workflow_id' => $workflow->id,
                    'direction'   => $direction,
                    'period'      => StatPeriod::Daily->value,
                    'period_date' => $this->date,
                ],
                [
                    'endpoint_id'     => null,
                    'total_count'     => $total,
                    'success_count'   => $success,
                    'failed_count'    => $failed,
                    'dlq_count'       => $dlq,
                    'skipped_count'   => $skipped,
                    'avg_duration_ms' => $avgMs,
                    'p95_duration_ms' => $p95Ms,
                    'total_bytes_in'  => 0,
                    'total_bytes_out' => 0,
                ]
            );

            // Check the error rate threshold for notifications
            $errorRate = $total > 0 ? (($failed + $dlq) / $total) * 100 : 0.0;
            $notifier->notifyHighErrorRate($workflow, round($errorRate, 2), $this->date);
        }
    }
}
