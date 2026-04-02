<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Observers;

use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Models\N8nQueueCheckpoint;
use Oriceon\N8nBridge\Models\N8nQueueJob;
use Oriceon\N8nBridge\Queue\Workers\WorkflowDurationUpdater;

/**
 * Observer on N8nQueueJob.
 *
 * Responsibilities:
 *   1. Auto-delete checkpoints when a job completes successfully
 *      (configurable via n8n-bridge.queue.delete_checkpoints_on_success)
 *   2. Trigger WorkflowDurationUpdater to update the rolling EMA
 *      after every successful job
 *
 * Registered via #[ObservedBy([N8nQueueJobObserver::class])] on N8nQueueJob
 * (Laravel 13 attribute — no manual registration in ServiceProvider needed).
 */
final readonly class N8nQueueJobObserver
{
    public function __construct(
        private WorkflowDurationUpdater $durationUpdater,
    ) {
    }

    public function updated(N8nQueueJob $job): void
    {
        // Only act on status changes to Done
        if ( ! $job->wasChanged('status')) {
            return;
        }

        if ($job->status !== QueueJobStatus::Done) {
            return;
        }

        // 1. Update rolling duration estimate
        $this->durationUpdater->record($job);

        // 2. Auto-delete checkpoints on success (if enabled)
        if (config('n8n-bridge.queue.delete_checkpoints_on_success', true)) {
            N8nQueueCheckpoint::query()
                ->where('job_id', $job->id)
                ->delete();
        }
    }
}
