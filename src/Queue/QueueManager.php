<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Queue;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Oriceon\N8nBridge\Enums\QueueJobPriority;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Models\N8nQueueBatch;
use Oriceon\N8nBridge\Models\N8nQueueFailure;
use Oriceon\N8nBridge\Models\N8nQueueJob;
use Oriceon\N8nBridge\Models\N8nWorkflow;

/**
 * High-level API for the n8n DB queue system.
 *
 * Accessible via:
 *   N8nBridge::queue()->dispatch(...)
 *   N8nBridge::queue()->stats()
 *   N8nBridge::queue()->batch($id)
 *
 * Or via the QueueDispatcher directly for more control.
 */
final class QueueManager
{
    // ── Dispatching ───────────────────────────────────────────────────────────

    /**
     * Dispatch a single job. Fluent shortcut.
     *
     * Example:
     *   N8nBridge::queue()->dispatch('invoice-paid', ['invoice_id' => 42]);
     *   N8nBridge::queue()->dispatch('invoice-paid', $payload, QueueJobPriority::High);
     *
     * @param N8nWorkflow|string $workflow
     * @param array $payload
     * @param QueueJobPriority $priority
     * @param int $delaySeconds
     * @param string|null $idempotencyKey
     * @return N8nQueueJob
     */
    public function dispatch(
        N8nWorkflow|string $workflow,
        array $payload    = [],
        QueueJobPriority $priority   = QueueJobPriority::Normal,
        int $delaySeconds = 0,
        ?string $idempotencyKey = null,
    ): N8nQueueJob {
        $builder = QueueDispatcher::workflow($workflow)
            ->payload($payload)
            ->priority($priority);

        if ($delaySeconds > 0) {
            $builder->delay(seconds: $delaySeconds);
        }

        if ($idempotencyKey !== null) {
            $builder->idempotent($idempotencyKey);
        }

        return $builder->dispatch();
    }

    /**
     * Dispatch many jobs at once (bulk insert).
     *
     * Example:
     *   N8nBridge::queue()
     *       ->dispatchMany('invoice-reminder', $invoices->map(fn($i) => ['id' => $i->id]));
     *
     * @param N8nWorkflow|string $workflow
     * @param iterable $payloads
     * @param QueueJobPriority $priority
     * @param string $batchName
     * @param int $chunkSize
     * @throws \JsonException
     * @return N8nQueueBatch
     */
    public function dispatchMany(
        N8nWorkflow|string $workflow,
        iterable $payloads,
        QueueJobPriority $priority  = QueueJobPriority::Bulk,
        string $batchName = '',
        int $chunkSize = 500,
    ): N8nQueueBatch {
        return QueueDispatcher::batch($batchName ?: 'Bulk dispatch')
            ->forWorkflow($workflow)
            ->priority($priority)
            ->dispatchMany($payloads, $chunkSize);
    }

    /**
     * Dispatch jobs built from an Eloquent query (memory-efficient, uses chunkById).
     *
     * Example:
     *   N8nBridge::queue()->dispatchFromQuery(
     *       'invoice-reminder',
     *       Invoice::overdue()->with('customer'),
     *       fn($invoice) => ['invoice_id' => $invoice->id, 'email' => $invoice->customer->email],
     *   );
     *
     * @param N8nWorkflow|string $workflow
     * @param Builder $query
     * @param \Closure $map
     * @param QueueJobPriority $priority
     * @param string $batchName
     * @param int $chunkSize
     * @return N8nQueueBatch
     */
    public function dispatchFromQuery(
        N8nWorkflow|string $workflow,
        Builder $query,
        \Closure $map,
        QueueJobPriority $priority  = QueueJobPriority::Bulk,
        string $batchName = '',
        int $chunkSize = 1000,
    ): N8nQueueBatch {
        return QueueDispatcher::batch($batchName ?: 'Query dispatch')
            ->forWorkflow($workflow)
            ->priority($priority)
            ->dispatchFromQuery($query, $map, $chunkSize);
    }

    // ── Querying ──────────────────────────────────────────────────────────────

    /**
     * @param int|string $id
     * @return N8nQueueJob|null
     */
    public function job(int|string $id): ?N8nQueueJob
    {
        return N8nQueueJob::find($id);
    }

    /**
     * @param int|string $id
     * @return N8nQueueBatch|null
     */
    public function batch(int|string $id): ?N8nQueueBatch
    {
        return N8nQueueBatch::find($id);
    }

    /**
     * @param string $queue
     * @return int
     */
    public function pendingCount(string $queue = 'default'): int
    {
        return N8nQueueJob::query()->pending()->forQueue($queue)->count();
    }

    /**
     * @param string $queue
     * @return Collection
     */
    public function deadLetters(string $queue = 'default'): Collection
    {
        return N8nQueueJob::query()
            ->deadLetters()
            ->forQueue($queue)
            ->with('workflow')
            ->latest()
            ->get();
    }

    // ── Stats ─────────────────────────────────────────────────────────────────

    /**
     * Returns a stat array suitable for dashboards.
     *
     * [
     *   'pending'         => 142,
     *   'running'         => 3,
     *   'done_today'      => 8431,
     *   'failed_today'    => 12,
     *   'dead_total'      => 7,
     *   'avg_duration_ms' => 238,
     *   'success_rate'    => 99.86,
     *   'by_priority'     => ['Critical' => 2, 'High' => 18, ...],
     *   'by_reason'       => ['http_5xx' => 9, 'connection_timeout' => 3, ...],
     * ]
     *
     * @param string $queue
     * @return array
     */
    public function stats(string $queue = 'default'): array
    {
        $base = N8nQueueJob::query()->forQueue($queue);

        $pendingByPriority = [];

        foreach (QueueJobPriority::cases() as $p) {
            $count = (clone $base)->pending()->where('priority', $p->value)->count();

            if ($count > 0) {
                $pendingByPriority[$p->label()] = $count;
            }
        }

        $failuresByReason = N8nQueueFailure::query()
            ->lastHours(24)
            ->selectRaw('reason, count(*) as cnt')
            ->groupBy('reason')
            ->pluck('cnt', 'reason')
            ->toArray();

        $doneToday  = (clone $base)->where('status', QueueJobStatus::Done->value)
            ->where('finished_at', '>=', today())->count();
        $failedToday = (clone $base)
            ->whereIn('status', [QueueJobStatus::Failed->value, QueueJobStatus::Dead->value])
            ->where('updated_at', '>=', today())->count();

        $avgDuration = (clone $base)
            ->where('status', QueueJobStatus::Done->value)
            ->whereNotNull('duration_ms')
            ->where('finished_at', '>=', now()->subHours(1))
            ->avg('duration_ms');

        $processed = $doneToday + $failedToday;

        return [
            'pending'         => (clone $base)->pending()->count(),
            'running'         => (clone $base)->active()->count(),
            'done_today'      => $doneToday,
            'failed_today'    => $failedToday,
            'dead_total'      => (clone $base)->deadLetters()->count(),
            'avg_duration_ms' => $avgDuration ? (int) round($avgDuration) : null,
            'success_rate'    => $processed > 0 ? round($doneToday / $processed * 100, 2) : null,
            'by_priority'     => $pendingByPriority,
            'by_reason'       => $failuresByReason,
        ];
    }
}
