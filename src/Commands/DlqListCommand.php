<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Enums\DeliveryStatus;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'n8n:dlq:list')]
#[Signature('n8n:dlq:list {--endpoint= : Filter by endpoint slug} {--limit=20}')]
#[Description('List Dead Letter Queue entries')]
final class DlqListCommand extends Command
{
    public function handle(): int
    {
        $query = N8nDelivery::query()
            ->with(['workflow:id,name', 'endpoint:id,slug'])
            ->where('status', DeliveryStatus::Dlq->value)
            ->latest()
            ->limit((int) $this->option('limit'));

        if ($slug = $this->option('endpoint')) {
            $query->whereHas('endpoint', fn($q) => $q->where('slug', $slug));
        }

        $entries = $query->get();

        if ($entries->isEmpty()) {
            $this->info('✅ No DLQ entries.');

            return self::SUCCESS;
        }

        $this->warn("⚠️  {$entries->count()} DLQ entries:");

        $this->table(
            ['ID', 'Workflow', 'Endpoint', 'Error', 'Attempts', 'Date'],
            $entries->map(fn($d) => [
                substr($d->uuid, 0, 8) . '...',
                $d->workflow?->name ?? '—',
                $d->endpoint?->slug ?? '—',
                substr($d->error_message ?? '', 0, 50),
                $d->attempts,
                $d->created_at?->diffForHumans(),
            ])
        );

        return self::SUCCESS;
    }
}
