<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Enums\DeliveryStatus;
use Oriceon\N8nBridge\Jobs\ProcessN8nInboundJob;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'n8n:dlq:retry')]
#[Signature('n8n:dlq:retry {id? : Specific delivery UUID (omit for all)}')]
#[Description('Re-queue DLQ deliveries for reprocessing')]
final class DlqRetryCommand extends Command
{
    public function handle(): int
    {
        $id    = $this->argument('id');
        $query = N8nDelivery::query()->where('status', DeliveryStatus::Dlq->value)->with('endpoint');

        if ($id !== null) {
            $query->where('uuid', $id);
        }

        $deliveries = $query->get();

        if ($deliveries->isEmpty()) {
            $this->info('No DLQ entries to retry.');

            return self::SUCCESS;
        }

        $count = 0;

        foreach ($deliveries as $delivery) {
            if ($delivery->endpoint_id === null) {
                $this->warn("Skipping delivery [{$delivery->uuid}] — no endpoint attached.");
                continue;
            }

            $delivery->update(['status' => DeliveryStatus::Received, 'attempts' => 0]);
            dispatch(new ProcessN8nInboundJob($delivery->id, $delivery->endpoint_id))
                ->onQueue($delivery->endpoint?->queue ?? 'default');
            ++$count;
        }

        $this->info("✅ Re-queued {$count} deliveries.");

        return self::SUCCESS;
    }
}
