<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands\Queue;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Models\N8nQueueBatch;
use Oriceon\N8nBridge\Models\N8nQueueFailure;
use Oriceon\N8nBridge\Models\N8nQueueJob;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Prune old completed/dead/cancelled jobs and their failure records.
 *
 * php artisan n8n:queue:prune
 * php artisan n8n:queue:prune --days=7
 * php artisan n8n:queue:prune --status=done --days=3
 */
#[AsCommand(name: 'n8n:queue:prune')]
#[Signature('n8n:queue:prune
        {--days=30    : Delete jobs older than N days}
        {--status=    : Only delete jobs with this status (done/dead/cancelled)}
        {--dry-run    : Show counts without deleting}')]
#[Description('Prune old completed n8n queue jobs and failure records')]
final class QueuePruneCommand extends Command
{
    public function handle(): int
    {
        $days    = (int) $this->option('days');
        $status  = $this->option('status');
        $cutoff  = now()->subDays($days);

        $query = N8nQueueJob::query()->where('updated_at', '<', $cutoff);

        if ($status) {
            $query->where('status', $status);
        }
        else {
            // Only prune terminal statuses by default
            $query->whereIn('status', [
                QueueJobStatus::Done->value,
                QueueJobStatus::Dead->value,
                QueueJobStatus::Cancelled->value,
            ]);
        }

        $jobCount     = $query->count();
        $jobIds       = (clone $query)->pluck('id');
        $failureCount = N8nQueueFailure::query()->whereIn('job_id', $jobIds)->count();

        if ($this->option('dry-run')) {
            $this->info('[dry-run] Would delete:');
            $this->line("  Jobs:     {$jobCount}");
            $this->line("  Failures: {$failureCount}");

            return self::SUCCESS;
        }

        N8nQueueFailure::query()->whereIn('job_id', $jobIds)->delete();
        $query->delete();

        // Prune finished batches
        $batchCount = N8nQueueBatch::query()
            ->where('updated_at', '<', $cutoff)
            ->whereNotNull('finished_at')
            ->delete();

        $this->info("✅ Pruned: {$jobCount} jobs, {$failureCount} failure records, {$batchCount} batches.");

        return self::SUCCESS;
    }
}
