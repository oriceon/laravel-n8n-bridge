<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands\Queue;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Models\N8nQueueBatch;
use Oriceon\N8nBridge\Models\N8nQueueJob;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Cancel pending or failed jobs before they are processed.
 *
 * php artisan n8n:queue:cancel {id}
 * php artisan n8n:queue:cancel --batch={batchId}
 * php artisan n8n:queue:cancel --workflow=invoice-paid
 */
#[AsCommand(name: 'n8n:queue:cancel')]
#[Signature('n8n:queue:cancel
        {id?             : Specific job UUID to cancel}
        {--batch=        : Cancel all pending jobs in a batch}
        {--workflow=     : Cancel all pending jobs for a workflow}
        {--reason=       : Reason for cancellation}
        {--dry-run       : Show what would be cancelled}')]
#[Description('Cancel pending n8n queue jobs')]
final class QueueCancelCommand extends Command
{
    public function handle(): int
    {
        $reason = (string) ($this->option('reason') ?: 'Manually cancelled via Artisan');

        if ($id = $this->argument('id')) {
            $job = N8nQueueJob::where('uuid', $id)->first();

            if ($job === null) {
                $this->error("Job [{$id}] not found.");

                return self::FAILURE;
            }

            if ($job->status->isTerminal() || $job->status->isActive()) {
                $this->warn("Job [{$id}] is in [{$job->status->value}] — cannot cancel.");

                return self::FAILURE;
            }

            if ($this->option('dry-run')) {
                $this->info("[dry-run] Would cancel job [{$id}]");

                return self::SUCCESS;
            }
            $job->cancel($reason);
            $this->info("✅ Job [{$id}] cancelled.");

            return self::SUCCESS;
        }

        if ($batchId = $this->option('batch')) {
            $batch = N8nQueueBatch::where('uuid', $batchId)->first();

            if ($batch === null) {
                $this->error("Batch [{$batchId}] not found.");

                return self::FAILURE;
            }

            if ($this->option('dry-run')) {
                $count = N8nQueueJob::query()->forBatch((string) $batch->id)->pending()->count();
                $this->info("[dry-run] Would cancel {$count} pending jobs in batch [{$batch->name}]");

                return self::SUCCESS;
            }
            $batch->cancel();
            $this->info("✅ Batch [{$batch->name}] cancelled.");

            return self::SUCCESS;
        }

        if ($workflow = $this->option('workflow')) {
            $query = N8nQueueJob::query()
                ->whereIn('status', [QueueJobStatus::Pending->value, QueueJobStatus::Failed->value])
                ->whereHas('workflow', fn($q) => $q->where('name', $workflow));

            $count = $query->count();

            if ($count === 0) {
                $this->info("No pending jobs found for workflow [{$workflow}].");

                return self::SUCCESS;
            }

            if ($this->option('dry-run')) {
                $this->info("[dry-run] Would cancel {$count} jobs for workflow [{$workflow}]");

                return self::SUCCESS;
            }

            if ( ! $this->confirm("Cancel {$count} job(s) for workflow [{$workflow}]?", true)) {
                return self::SUCCESS;
            }
            $query->update([
                'status'             => QueueJobStatus::Cancelled->value,
                'last_error_message' => $reason,
                'worker_id'          => null,
                'reserved_until'     => null,
            ]);
            $this->info("✅ Cancelled {$count} jobs for workflow [{$workflow}].");

            return self::SUCCESS;
        }

        $this->error('Specify a job ID, --batch=, or --workflow=');

        return self::FAILURE;
    }
}
