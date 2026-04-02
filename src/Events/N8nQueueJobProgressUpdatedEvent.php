<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Oriceon\N8nBridge\Models\N8nQueueCheckpoint;
use Oriceon\N8nBridge\Models\N8nQueueJob;

/**
 * Fired every time a new checkpoint arrives from n8n.
 *
 * Implements ShouldBroadcast so it can be pushed live to the frontend
 * via Laravel Reverb, Pusher, or any other broadcast driver.
 *
 * Frontend (Echo):
 *   window.Echo
 *     .private(`n8n-job.${jobId}`)
 *     .listen('N8nQueueJobProgressUpdatedEvent', (e) => {
 *         console.log(e.checkpoint);
 *         updateTimeline(e.checkpoint);
 *     });
 *
 * Or with Livewire:
 *   #[On('echo-private:n8n-job.{jobId},N8nQueueJobProgressUpdatedEvent')]
 *   public function onProgress(array $data): void { ... }
 */
final class N8nQueueJobProgressUpdatedEvent implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    /**
     * @param N8nQueueJob $job
     * @param N8nQueueCheckpoint $checkpoint
     */
    public function __construct(
        public readonly N8nQueueJob $job,
        public readonly N8nQueueCheckpoint $checkpoint,
    ) {
    }

    /**
     * Broadcast on a private channel per job.
     * Only authenticated users who know the job ID can subscribe.
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('n8n-job.' . $this->job->id),
        ];
    }

    /**
     * The event name used on the frontend.
     */
    public function broadcastAs(): string
    {
        return 'N8nQueueJobProgressUpdatedEvent';
    }

    /**
     * Data sent to the frontend.
     */
    public function broadcastWith(): array
    {
        return [
            'job_id'     => $this->job->id,
            'job_status' => $this->job->status->value,
            'checkpoint' => [
                'id'       => $this->checkpoint->id,
                'node'     => $this->checkpoint->node_name,
                'label'    => $this->checkpoint->node_label ?? $this->checkpoint->node_name,
                'status'   => $this->checkpoint->status->value,
                'color'    => $this->checkpoint->status->color(),
                'icon'     => $this->checkpoint->status->icon(),
                'message'  => $this->checkpoint->message,
                'data'     => $this->checkpoint->data,
                'error'    => $this->checkpoint->error_message,
                'progress' => $this->checkpoint->progress_percent,
                'sequence' => $this->checkpoint->sequence,
                'at'       => $this->checkpoint->created_at->toIso8601String(),
            ],
            'is_terminal' => $this->checkpoint->isTerminal() &&
                in_array($this->checkpoint->node_name, ['__done__', '__failed__'], true),
        ];
    }
}
