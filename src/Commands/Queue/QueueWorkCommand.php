<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands\Queue;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Queue\Workers\QueueWorker;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Long-running Artisan command to process n8n queue jobs.
 *
 * Usage:
 *   php artisan n8n:queue:work
 *   php artisan n8n:queue:work --queue=high --sleep=1 --max-jobs=100
 *   php artisan n8n:queue:work --queue=bulk --sleep=5 --max-time=3600
 *
 * Run multiple workers for different priority queues:
 *   php artisan n8n:queue:work --queue=critical        # dedicated critical worker
 *   php artisan n8n:queue:work --queue=high,normal     # shared worker
 *   php artisan n8n:queue:work --queue=bulk            # background worker
 */
#[AsCommand(name: 'n8n:queue:work')]
#[Signature('n8n:queue:work
        {--queue=default  : Queue name(s) to consume, comma-separated}
        {--sleep=1        : Seconds to sleep when no jobs available}
        {--max-jobs=0     : Stop after processing N jobs (0 = run forever)}
        {--max-time=0     : Stop after N seconds (0 = run forever)}
        {--once           : Process only one job then exit}
        {--recover        : Recover stuck jobs then exit (no processing)}')]
#[Description('Process n8n queue jobs from the database queue')]
final class QueueWorkCommand extends Command
{
    /**
     * @param QueueWorker $worker
     * @throws \Throwable
     * @return int
     */
    public function handle(QueueWorker $worker): int
    {
        $queue   = (string) $this->option('queue');
        $sleep   = (int) $this->option('sleep');
        $maxJobs = (int) $this->option('max-jobs');
        $maxTime = (int) $this->option('max-time');

        if ($this->option('recover')) {
            $recovered = $worker->recoverStuckJobs($queue);
            $this->info("Recovered {$recovered} stuck jobs.");

            return self::SUCCESS;
        }

        if ($this->option('once')) {
            $maxJobs = 1;
        }

        $this->info("Starting n8n queue worker [{$worker->workerId()}]");
        $this->info("Queue: {$queue} | Sleep: {$sleep}s | Max jobs: " . ($maxJobs ?: '∞'));

        $worker->run(
            queueName: $queue,
            sleep:     $sleep,
            maxJobs:   $maxJobs,
            maxTime:   $maxTime,
        );

        $this->info('Worker stopped cleanly.');

        return self::SUCCESS;
    }
}
