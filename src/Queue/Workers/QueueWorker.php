<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Queue\Workers;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Oriceon\N8nBridge\Auth\WebhookAuthService;
use Oriceon\N8nBridge\CircuitBreaker\CircuitBreakerManager;
use Oriceon\N8nBridge\Client\N8nApiClient;
use Oriceon\N8nBridge\Enums\QueueFailureReason;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Events\N8nQueueBatchCompletedEvent;
use Oriceon\N8nBridge\Events\N8nQueueJobCompletedEvent;
use Oriceon\N8nBridge\Events\N8nQueueJobFailedEvent;
use Oriceon\N8nBridge\Events\N8nQueueJobStartedEvent;
use Oriceon\N8nBridge\Models\N8nQueueBatch;
use Oriceon\N8nBridge\Models\N8nQueueFailure;
use Oriceon\N8nBridge\Models\N8nQueueJob;
use Oriceon\N8nBridge\N8nBridgeManager;
use Oriceon\N8nBridge\Notifications\NotificationDispatcher;
use Oriceon\N8nBridge\Outbound\OutboundRateLimiter;

/**
 * Core polling worker that claims and processes queue jobs.
 *
 * Designed to run in a loop inside the Artisan command.
 * Handles:
 * - Atomic job claiming with SELECT FOR UPDATE SKIP LOCKED
 * - Circuit breaker integration
 * - Full failure recording
 * - Batch progress tracking
 * - Stuck job recovery
 * - Graceful shutdown on SIGTERM
 */
final class QueueWorker
{
    private bool $shouldStop = false;

    private string $workerId;

    public function __construct(
        private readonly N8nBridgeManager $bridge,
        private readonly CircuitBreakerManager $circuitBreaker,
        private readonly NotificationDispatcher $notifier,
        private readonly WebhookAuthService $outboundAuth,
        private readonly OutboundRateLimiter $rateLimiter,
    ) {
        $this->workerId = gethostname() . ':' . getmypid() . ':' . Str::uuid();

        // Graceful shutdown on SIGTERM / SIGINT
        if (extension_loaded('pcntl')) {
            pcntl_signal(SIGTERM, fn() => $this->shouldStop = true);
            pcntl_signal(SIGINT, fn() => $this->shouldStop = true);
        }
    }

    // ── Main loop ─────────────────────────────────────────────────────────────

    /**
     * Run the worker loop.
     *
     * @param  string  $queueName  Which queue to consume ('default', 'high', etc.)
     * @param  int  $sleep  Seconds to sleep when no jobs available
     * @param  int  $maxJobs  Stop after processing this many jobs (0 = infinite)
     * @param  int  $maxTime  Stop after this many seconds (0 = infinite)
     *
     * @throws \Throwable
     */
    public function run(
        string $queueName = 'default',
        int $sleep = 1,
        int $maxJobs = 0,
        int $maxTime = 0,
    ): void {
        $processed = 0;
        $startedAt = time();

        $this->recoverStuckJobs($queueName);

        while ( ! $this->shouldStop) {
            if (extension_loaded('pcntl')) {
                pcntl_signal_dispatch();
            }

            // Stop conditions
            if ($maxJobs > 0 && $processed >= $maxJobs) {
                break;
            }

            if ($maxTime > 0 && (time() - $startedAt) >= $maxTime) {
                break;
            }

            $job = $this->claim($queueName);

            if ($job === null) {
                sleep($sleep);

                continue;
            }

            $this->process($job);
            ++$processed;
        }
    }

    // ── Job claiming ──────────────────────────────────────────────────────────

    /**
     * Atomically claim the next available job using SELECT FOR UPDATE SKIP LOCKED.
     * This is safe for multiple concurrent workers on the same table.
     *
     * @throws \Throwable
     */
    private function claim(string $queueName): ?N8nQueueJob
    {
        return DB::transaction(function() use ($queueName): ?N8nQueueJob {
            /** @var N8nQueueJob|null $job */
            $job = N8nQueueJob::query()
                ->with('workflow')
                ->available()
                ->forQueue($queueName)
                ->lockForUpdate()
                ->first();

            if ($job === null) {
                return null;
            }

            // Double-check inside transaction (race condition guard)
            if ($job->status !== QueueJobStatus::Pending) {
                return null;
            }

            $leaseSeconds = $job->timeout_seconds + 30; // buffer above timeout
            $job->claim($this->workerId, $leaseSeconds);

            return $job;
        });
    }

    // ── Job processing ────────────────────────────────────────────────────────

    private function process(N8nQueueJob $job): void
    {
        $startedAt = microtime(true);
        $job->markRunning();

        event(new N8nQueueJobStartedEvent($job));

        try {
            // Circuit breaker check before dispatching
            $cbState = $this->circuitBreaker->getState($job->workflow);

            if ( ! $cbState->allowsRequests()) {
                $this->handleFailure(
                    job: $job,
                    reason: QueueFailureReason::CircuitBreakerOpen,
                    message: "Circuit breaker is OPEN for workflow [{$job->workflow->name}]",
                    durationMs: 0,
                );

                return;
            }

            // Rate limit check — release the job back to the queue with a delay
            $waitSeconds = $this->rateLimiter->check($job->workflow);

            if ($waitSeconds !== null) {
                $job->update([
                    'status'       => QueueJobStatus::Pending->value,
                    'worker_id'    => null,
                    'available_at' => now()->addSeconds($waitSeconds),
                ]);

                Log::channel(config('n8n-bridge.queue.log_channel', 'stack'))
                    ->debug("[n8n-queue] Job {$job->id} rate-limited — releasing for {$waitSeconds}s", [
                        'workflow' => $job->workflow->name,
                    ]);

                return;
            }

            // Build n8n client for the correct instance
            $client = $this->bridge->client($job->n8n_instance ?? 'default');

            // Execute the HTTP call
            $response   = $this->callN8n($client, $job);
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            $job->markDone($response);

            $this->circuitBreaker->recordSuccess($job->workflow);

            // Update batch progress
            if ($job->batch_id) {
                $this->updateBatchProgress($job->batch_id);
            }

            event(new N8nQueueJobCompletedEvent($job));

            Log::channel(config('n8n-bridge.queue.log_channel', 'stack'))
                ->debug("[n8n-queue] Job {$job->id} completed in {$durationMs}ms", [
                    'workflow'     => $job->workflow->name,
                    'priority'     => $job->priority->label(),
                    'execution_id' => $response['execution_id'] ?? 'n/a',
                ]);

        }
        catch (\Throwable $e) {
            $durationMs = (int) ((microtime(true) - $startedAt) * 1000);

            $reason  = $this->classifyException($e);
            $message = $e->getMessage();

            $this->handleFailure(
                job: $job,
                reason: $reason,
                message: $message,
                errorClass: $e::class,
                durationMs: $durationMs,
                stackTrace: $e->getTraceAsString(),
            );
        }
    }

    // ── n8n HTTP call ─────────────────────────────────────────────────────────

    /**
     * @throws \JsonException
     */
    private function callN8n(N8nApiClient $client, N8nQueueJob $job): array
    {
        $workflow = $job->workflow;

        if (empty($workflow->webhook_path)) {
            throw new \RuntimeException("Workflow [{$workflow->name}] has no webhook_path configured.");
        }

        $jsonBody    = (string) json_encode($job->payload, JSON_THROW_ON_ERROR);
        $authHeaders = $this->outboundAuth->buildHeaders($workflow, $jsonBody);

        return $client->triggerWebhook(
            url: $workflow->resolveWebhookUrl(),
            payload: $job->payload,
            method: $workflow->http_method->value,
            extraHeaders: $authHeaders,
        );
    }

    // ── Failure handling ──────────────────────────────────────────────────────

    private function handleFailure(
        N8nQueueJob $job,
        QueueFailureReason $reason,
        string $message,
        string $errorClass = '',
        int $durationMs = 0,
        int $httpStatus = 0,
        array $httpResponse = [],
        ?string $stackTrace = null,
    ): void {
        // Append to failure history (always)
        $failure = N8nQueueFailure::recordFromJob(
            job: $job,
            reason: $reason,
            errorMessage: $message,
            errorClass: $errorClass,
            httpStatus: $httpStatus,
            httpResponse: $httpResponse,
            durationMs: $durationMs,
            stackTrace: $stackTrace,
        );

        // Update circuit breaker
        $this->circuitBreaker->recordFailure($job->workflow);

        // Determine delay for retry
        $delaySeconds = $reason->suggestedDelaySeconds();

        if ($reason === QueueFailureReason::RateLimit) {
            // Back off longer for rate limits using attempt count
            $delaySeconds = min(300, 60 * $job->attempts);
        }

        $job->markFailed($reason, $message, $errorClass, $delaySeconds);

        // Refresh to get an updated status (Dead or Failed+Pending)
        $job->refresh();

        event(new N8nQueueJobFailedEvent($job, $failure));

        // Alert on Dead jobs
        if ($job->status === QueueJobStatus::Dead) {
            $this->notifier->notifyDeadQueueJob($job);
        }

        // Update batch
        if ($job->batch_id) {
            $this->updateBatchProgress($job->batch_id);
        }

        Log::channel(config('n8n-bridge.queue.log_channel', 'stack'))
            ->warning("[n8n-queue] Job {$job->id} failed ({$reason->label()})", [
                'workflow' => $job->workflow->name,
                'attempt'  => $job->attempts,
                'message'  => substr($message, 0, 200),
                'status'   => $job->status->value,
            ]);
    }

    // ── Batch progress ────────────────────────────────────────────────────────

    private function updateBatchProgress(string $batchId): void
    {
        $batch = N8nQueueBatch::find($batchId);

        if ($batch === null) {
            return;
        }

        $batch->recalculate();

        if ($batch->isComplete()) {
            event(new N8nQueueBatchCompletedEvent($batch));
        }
    }

    // ── Stuck job recovery ────────────────────────────────────────────────────

    /**
     * Recover jobs that were claimed but the worker died.
     * Called at startup to clean up orphaned jobs from a previous crash.
     */
    public function recoverStuckJobs(string $queueName, int $minutes = 10): int
    {
        $stuck = N8nQueueJob::query()
            ->forQueue($queueName)
            ->stuck($minutes)
            ->get();

        $count = 0;

        foreach ($stuck as $job) {
            // Reset to pending with full retry budget preserved
            $job->update([
                'status'         => QueueJobStatus::Pending->value,
                'worker_id'      => null,
                'reserved_until' => null,
                'available_at'   => now()->addSeconds(30), // brief delay before retry
            ]);
            ++$count;
        }

        if ($count > 0) {
            Log::warning("[n8n-queue] Recovered {$count} stuck jobs on queue [{$queueName}]");
        }

        return $count;
    }

    // ── Exception classification ──────────────────────────────────────────────

    private function classifyException(\Throwable $e): QueueFailureReason
    {
        // Check HTTP response status if available
        if (method_exists($e, 'getCode') && $e->getCode() > 0) {
            return QueueFailureReason::fromHttpStatus($e->getCode());
        }

        return QueueFailureReason::fromException($e);
    }

    public function shouldStop(): bool
    {
        return $this->shouldStop;
    }

    public function workerId(): string
    {
        return $this->workerId;
    }
}
