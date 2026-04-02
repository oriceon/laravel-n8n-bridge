<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Queue;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Oriceon\N8nBridge\Enums\QueueJobPriority;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Models\N8nQueueBatch;
use Oriceon\N8nBridge\Models\N8nQueueJob;
use Oriceon\N8nBridge\Models\N8nWorkflow;

/**
 * Fluent builder for dispatching n8n queue jobs.
 *
 * Usage:
 *
 *   // Single job
 *   QueueDispatcher::workflow('invoice-reminder')
 *       ->payload(['invoice_id' => 42])
 *       ->priority(QueueJobPriority::High)
 *       ->delay(minutes: 5)
 *       ->dispatch();
 *
 *   // Bulk batch
 *   QueueDispatcher::batch('Monthly reminders')
 *       ->workflow('invoice-reminder')
 *       ->priority(QueueJobPriority::Bulk)
 *       ->dispatchMany($invoices->map(fn($i) => ['invoice_id' => $i->id]));
 */
final class QueueDispatcher
{
    private ?N8nWorkflow $workflow = null;

    private array $payload = [];

    private array $context = [];

    private QueueJobPriority $priority = QueueJobPriority::Normal;

    private ?int $delaySeconds = null;

    private ?string $queueName = null;

    private ?int $maxAttempts = null;

    private ?int $timeoutSeconds = null;

    private ?string $idempotencyKey = null;

    private ?string $n8nInstance = null;

    // Batch mode
    private ?string $batchName = null;

    private ?string $batchDescription = null;

    private ?N8nQueueBatch $activeBatch = null;

    // ── Static factory methods ────────────────────────────────────────────────

    /**
     * Start building a single job for a given workflow (by name or model).
     */
    public static function workflow(N8nWorkflow|string $workflow): self
    {
        $instance = new self();

        $instance->workflow = is_string($workflow)
            ? N8nWorkflow::query()->active()->where('name', $workflow)->firstOrFail()
            : $workflow;

        return $instance;
    }

    /**
     * Start building a batch.
     */
    public static function batch(string $name, ?string $description = null): self
    {
        $instance = new self();

        $instance->batchName        = $name;
        $instance->batchDescription = $description;

        return $instance;
    }

    /**
     * @return $this
     */
    public function payload(array $payload): self
    {
        $this->payload = $payload;

        return $this;
    }

    /**
     * Extra metadata isn't sent to n8n — stored for debugging.
     * Example: ['triggered_by' => 'App\Listeners\OrderShippedListener', 'order_id' => 99]
     */
    public function context(array $context): self
    {
        $this->context = $context;

        return $this;
    }

    /**
     * @return $this
     */
    public function priority(QueueJobPriority $priority): self
    {
        $this->priority = $priority;

        return $this;
    }

    public function critical(): self
    {
        return $this->priority(QueueJobPriority::Critical);
    }

    public function high(): self
    {
        return $this->priority(QueueJobPriority::High);
    }

    public function normal(): self
    {
        return $this->priority(QueueJobPriority::Normal);
    }

    public function low(): self
    {
        return $this->priority(QueueJobPriority::Low);
    }

    public function bulk(): self
    {
        return $this->priority(QueueJobPriority::Bulk);
    }

    /**
     * @return $this
     */
    public function delay(int $seconds = 0, int $minutes = 0, int $hours = 0): self
    {
        $this->delaySeconds = $seconds + ($minutes * 60) + ($hours * 3600);

        return $this;
    }

    /**
     * @return $this
     */
    public function availableAt(CarbonInterface $at): self
    {
        $this->delaySeconds = (int) now()->diffInSeconds($at, absolute: true);

        return $this;
    }

    /**
     * @return $this
     */
    public function onQueue(string $name): self
    {
        $this->queueName = $name;

        return $this;
    }

    /**
     * @return $this
     */
    public function maxAttempts(int $max): self
    {
        $this->maxAttempts = $max;

        return $this;
    }

    /**
     * @return $this
     */
    public function timeout(int $seconds): self
    {
        $this->timeoutSeconds = $seconds;

        return $this;
    }

    /**
     * @return $this
     */
    public function idempotent(string $key): self
    {
        $this->idempotencyKey = $key;

        return $this;
    }

    /**
     * Set the workflow on the current instance (for batch chaining).
     *
     * Use this instead of the static workflow() factory when building a batch:
     *   QueueDispatcher::batch('name')->forWorkflow('my-workflow')->dispatchMany(...)
     *
     * @return $this
     */
    public function forWorkflow(N8nWorkflow|string $workflow): self
    {
        $this->workflow = is_string($workflow)
            ? N8nWorkflow::query()->active()->where('name', $workflow)->firstOrFail()
            : $workflow;

        return $this;
    }

    /**
     * @return $this
     */
    public function instance(string $n8nInstance): self
    {
        $this->n8nInstance = $n8nInstance;

        return $this;
    }

    // ── Dispatch ──────────────────────────────────────────────────────────────

    /**
     * Dispatch a single job.
     */
    public function dispatch(): N8nQueueJob
    {
        $this->ensureWorkflow();

        // Idempotency — skip if same key already pending or done
        if ($this->idempotencyKey !== null) {
            $existing = N8nQueueJob::query()
                ->where('idempotency_key', $this->idempotencyKey)
                ->first();

            if ($existing !== null) {
                // Return early if the job is still active
                if ( ! in_array($existing->status->value, [
                    QueueJobStatus::Dead->value,
                    QueueJobStatus::Cancelled->value,
                ], true)) {
                    return $existing;
                }

                // Release the key from the terminal job so a new one can use it
                $existing->update(['idempotency_key' => null]);
            }
        }

        return N8nQueueJob::create($this->buildAttributes());
    }

    /**
     * Dispatch many jobs in a single DB transaction (bulk insert).
     *
     * @param  iterable<array>  $payloads  Each element = payload for one job
     *
     * @throws \JsonException
     */
    public function dispatchMany(iterable $payloads, int $chunkSize = 500): N8nQueueBatch
    {
        $this->ensureWorkflow();

        $payloadsArray = collect($payloads);
        $total         = $payloadsArray->count();

        if ($total === 0) {
            throw new \InvalidArgumentException('Cannot dispatch an empty batch.');
        }

        if ($total > 100_000) {
            throw new \InvalidArgumentException("Batch size ({$total}) exceeds the 100,000 limit. Use dispatchFromQuery() for larger datasets.");
        }

        $batch = $this->createBatch();

        $batch->update([
            'total_jobs'   => $total,
            'pending_jobs' => $total,
            'started_at'   => now(),
        ]);

        // Bulk insert in chunks to avoid memory issues with large sets
        $payloadsArray->chunk($chunkSize)->each(function(Collection $chunk) use ($batch) {
            $now = now()->toDateTimeString();

            $rows = $chunk->map(fn(array $payload) => array_merge(
                $this->buildAttributes($batch->id),
                [
                    'uuid'       => (string) Str::uuid(),
                    'payload'    => json_encode($payload, JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            ))->all();

            N8nQueueJob::insert($rows);
        });

        return $batch->fresh();
    }

    /**
     * Dispatch jobs from a chunked Eloquent query (memory-efficient for millions of rows).
     *
     * @param  Builder  $query  Source query
     * @param  \Closure(object): array  $map  Transform model → payload
     */
    public function dispatchFromQuery(
        Builder $query,
        \Closure $map,
        int $chunkSize = 1000,
    ): N8nQueueBatch {
        $this->ensureWorkflow();

        $total = $query->count();
        $batch = $this->createBatch();
        $batch->update(['total_jobs' => $total, 'pending_jobs' => $total, 'started_at' => now()]);

        $query->chunkById($chunkSize, function($models) use ($batch, $map) {
            $now = now()->toDateTimeString();

            $rows = $models->map(fn($model) => array_merge(
                $this->buildAttributes($batch->id),
                [
                    'uuid'       => (string) Str::uuid(),
                    'payload'    => json_encode($map($model), JSON_THROW_ON_ERROR),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]
            ))->all();

            N8nQueueJob::insert($rows);
        });

        return $batch->fresh();
    }

    // ── Internal helpers ──────────────────────────────────────────────────────

    private function buildAttributes(int|string|null $batchId = null): array
    {
        $availableAt = $this->delaySeconds
            ? now()->addSeconds($this->delaySeconds)
            : null;

        return [
            'workflow_id'     => $this->workflow->id,
            'batch_id'        => $batchId,
            'priority'        => $this->priority->value,
            'status'          => QueueJobStatus::Pending->value,
            'payload'         => $this->payload,
            'context'         => $this->context ?: null,
            'n8n_instance'    => $this->n8nInstance ?? $this->workflow->n8n_instance ?? 'default',
            'max_attempts'    => $this->maxAttempts ?? $this->priority->defaultMaxAttempts(),
            'timeout_seconds' => $this->timeoutSeconds ?? $this->priority->defaultTimeoutSeconds(),
            'queue_name'      => $this->queueName ?? 'default',
            'idempotency_key' => $this->idempotencyKey,
            'available_at'    => $availableAt,
        ];
    }

    private function createBatch(): N8nQueueBatch
    {
        return N8nQueueBatch::create([
            'name'        => $this->batchName ?? $this->workflow?->name ?? 'Batch',
            'description' => $this->batchDescription,
            'priority'    => $this->priority->value,
        ]);
    }

    private function ensureWorkflow(): void
    {
        if ($this->workflow === null) {
            throw new \LogicException('No workflow set. Call ->workflow() before dispatching.');
        }
    }
}
