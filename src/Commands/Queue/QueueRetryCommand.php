<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands\Queue;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Enums\QueueFailureReason;
use Oriceon\N8nBridge\Enums\QueueJobPriority;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Models\N8nQueueJob;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Retry failed or dead queue jobs.
 *
 *   php artisan n8n:queue:retry                         # retry all dead jobs
 *   php artisan n8n:queue:retry {id}                    # retry one specific job
 *   php artisan n8n:queue:retry --workflow=invoice-paid # retry dead for one workflow
 *   php artisan n8n:queue:retry --reason=http_5xx       # retry by failure reason
 *   php artisan n8n:queue:retry --failed                # retry failed (not just dead)
 *   php artisan n8n:queue:retry --priority=high         # boost priority when retrying
 */
#[AsCommand(name: 'n8n:queue:retry')]
#[Signature('n8n:queue:retry
        {id?              : Specific job UUID to retry}
        {--workflow=      : Filter by workflow name}
        {--reason=        : Filter by failure reason (e.g. http_5xx, connection_timeout)}
        {--failed         : Include jobs with status=failed (not just dead)}
        {--priority=      : Override priority when retrying (critical/high/normal/low/bulk)}
        {--reset-attempts : Reset attempt counter to 0 (gives full retry budget)}
        {--batch=         : Filter by batch ID}
        {--dry-run        : Show what would be retried without making changes}
        {--limit=100      : Maximum jobs to retry at once}')]
#[Description('Retry failed or dead n8n queue jobs')]
final class QueueRetryCommand extends Command
{
    public function handle(): int
    {
        $id = $this->argument('id');

        // Single job
        if ($id) {
            return $this->retrySingle($id);
        }

        return $this->retryBulk();
    }

    /**
     * @param string $id
     * @return int
     */
    private function retrySingle(string $id): int
    {
        $job = N8nQueueJob::where('uuid', $id)->first();

        if ($job === null) {
            $this->error("Job [{$id}] not found.");

            return self::FAILURE;
        }

        if ($job->status !== QueueJobStatus::Dead && $job->status !== QueueJobStatus::Failed) {
            $this->warn("Job [{$id}] is in status [{$job->status->value}] — only dead/failed jobs can be retried.");

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Would retry job [{$id}] (workflow: {$job->workflow->name})");

            return self::SUCCESS;
        }

        $this->resetJob($job);
        $this->info("✅ Job [{$id}] queued for retry.");

        return self::SUCCESS;
    }

    private function retryBulk(): int
    {
        $query = N8nQueueJob::query();

        // Status filter
        $statuses = [QueueJobStatus::Dead->value];

        if ($this->option('failed')) {
            $statuses[] = QueueJobStatus::Failed->value;
        }
        $query->whereIn('status', $statuses);

        // Workflow filter
        if ($workflow = $this->option('workflow')) {
            $query->whereHas('workflow', fn($q) => $q->where('name', $workflow));
        }

        // Failure reason filter
        if ($reason = $this->option('reason')) {
            $enumCase = QueueFailureReason::tryFrom($reason);

            if ($enumCase === null) {
                $this->error("Unknown failure reason [{$reason}]. Valid: "
                    . implode(', ', array_column(QueueFailureReason::cases(), 'value')));

                return self::FAILURE;
            }
            $query->where('last_failure_reason', $reason);
        }

        // Batch filter
        if ($batch = $this->option('batch')) {
            $query->forBatch($batch);
        }

        $limit = (int) $this->option('limit');
        $query->limit($limit);

        $jobs  = $query->get();
        $count = $jobs->count();

        if ($count === 0) {
            $this->info('No jobs to retry.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->info("[dry-run] Would retry {$count} job(s):");
            $this->table(
                ['ID', 'Workflow', 'Priority', 'Attempts', 'Reason'],
                $jobs->map(fn($j) => [
                    substr($j->id, 0, 8) . '...',
                    $j->workflow->name ?? 'N/A',
                    $j->priority->label(),
                    "{$j->attempts}/{$j->max_attempts}",
                    $j->last_failure_reason?->label() ?? '-',
                ])
            );

            return self::SUCCESS;
        }

        if ( ! $this->confirm("Retry {$count} job(s)?", true)) {
            return self::SUCCESS;
        }

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        foreach ($jobs as $job) {
            $this->resetJob($job);
            $bar->advance();
        }

        $bar->finish();
        $this->newLine();
        $this->info("✅ {$count} job(s) queued for retry.");

        return self::SUCCESS;
    }

    /**
     * @param N8nQueueJob $job
     * @return void
     */
    private function resetJob(N8nQueueJob $job): void
    {
        $updates = [
            'status'         => QueueJobStatus::Pending->value,
            'worker_id'      => null,
            'reserved_until' => null,
            'available_at'   => null,
            'finished_at'    => null,
        ];

        // Override priority if requested
        if ($priority = $this->option('priority')) {
            // PHP 8.5: array_first() with filter callback
            $enumCase = array_first(
                array_filter(QueueJobPriority::cases(), static fn($p) => strtolower($p->name) === strtolower($priority))
            );

            if ($enumCase) {
                $updates['priority'] = $enumCase->value;
            }
        }

        // Reset attempt counter so it gets the full budget again
        if ($this->option('reset-attempts')) {
            $updates['attempts']     = 0;
            $updates['max_attempts'] = $job->priority->defaultMaxAttempts();
        }
        elseif ($job->status === QueueJobStatus::Dead) {
            // Give dead jobs one more attempt
            $updates['max_attempts'] = $job->attempts + 1;
        }

        $job->update($updates);

        // Mark the failure record as replayed
        $job->failures()
            ->orderByDesc('created_at')
            ->limit(1)
            ->update(['was_replayed' => true]);
    }
}
