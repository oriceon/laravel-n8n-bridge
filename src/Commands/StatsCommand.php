<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Facades\N8nBridge;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'n8n:stats')]
#[Signature('n8n:stats {--last=7 : Number of days}')]
#[Description('Show delivery statistics overview')]
final class StatsCommand extends Command
{
    public function handle(): int
    {
        $overview = N8nBridge::stats()->overview();

        $this->info('📊 n8n Bridge Statistics');

        $this->newLine();

        $this->table(
            ['Metric', 'Value'],
            [
                ['Total deliveries',  number_format($overview['total_deliveries'])],
                ['Success count',     number_format($overview['success_count'])],
                ['Failed count',      number_format($overview['failed_count'])],
                ['DLQ pending',       number_format($overview['dlq_pending'])],
                ['Success rate',      $overview['success_rate'] . '%'],
                ['Avg duration',      $overview['avg_duration_ms'] . 'ms'],
                ['Failed today',      number_format($overview['failed_today'])],
            ]
        );

        return self::SUCCESS;
    }
}
