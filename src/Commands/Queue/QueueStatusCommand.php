<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands\Queue;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Enums\QueueJobPriority;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Models\N8nQueueBatch;
use Oriceon\N8nBridge\Models\N8nQueueJob;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Show a real-time overview of the queue.
 *
 * php artisan n8n:queue:status
 * php artisan n8n:queue:status --queue=high --watch
 */
#[AsCommand(name: 'n8n:queue:status')]
#[Signature('n8n:queue:status
        {--queue=   : Filter by queue name}
        {--watch    : Refresh every 2 seconds}')]
#[Description('Show n8n queue job status overview')]
final class QueueStatusCommand extends Command
{
    public function handle(): int
    {
        do {
            $this->printStatus();

            if ($this->option('watch')) {
                sleep(2);
                $this->output->write("\033[2J\033[H"); // clear terminal
            }
        }
        while ($this->option('watch'));

        return self::SUCCESS;
    }

    private function printStatus(): void
    {
        $q = $this->option('queue');

        $query = N8nQueueJob::query();

        if ($q) {
            $query->forQueue($q);
        }

        // Status breakdown
        $breakdown = [];

        foreach (QueueJobStatus::cases() as $status) {
            $count = (clone $query)->where('status', $status->value)->count();

            if ($count > 0) {
                $breakdown[] = [$status->label(), number_format($count), $status->color()];
            }
        }

        $this->info('📋 n8n Queue Status' . ($q ? " [{$q}]" : ''));
        $this->newLine();
        $this->table(['Status', 'Count'], $breakdown);

        // Priority breakdown for pending jobs
        $pending = [];

        foreach (QueueJobPriority::cases() as $priority) {
            $count = (clone $query)
                ->pending()
                ->where('priority', $priority->value)
                ->count();

            if ($count > 0) {
                $pending[] = [$priority->label(), number_format($count)];
            }
        }

        if ( ! empty($pending)) {
            $this->newLine();
            $this->info('⏳ Pending by priority:');
            $this->table(['Priority', 'Count'], $pending);
        }

        // Stuck jobs
        $stuck = N8nQueueJob::query()->stuck()->count();

        if ($stuck > 0) {
            $this->warn("⚠️  {$stuck} stuck jobs detected (run n8n:queue:work --recover)");
        }

        // Active batches
        $batches = N8nQueueBatch::query()
            ->where('cancelled', false)
            ->whereNull('finished_at')
            ->latest()
            ->limit(5)
            ->get();

        if ($batches->isNotEmpty()) {
            $this->newLine();
            $this->info('📦 Active batches:');
            $this->table(
                ['Name', 'Total', 'Done', 'Failed', 'Dead', 'Progress'],
                $batches->map(fn($b) => [
                    $b->name,
                    $b->total_jobs,
                    $b->done_jobs,
                    $b->failed_jobs,
                    $b->dead_jobs,
                    $b->progressPercent() . '%',
                ])
            );
        }

        $this->line('<fg=gray>Updated: ' . now()->format('Y-m-d H:i:s') . '</>');
    }
}
