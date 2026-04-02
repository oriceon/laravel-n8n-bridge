<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'n8n:endpoint:list')]
#[Signature('n8n:endpoint:list {--active : Show only active endpoints}')]
#[Description('List all inbound endpoints')]
final class EndpointListCommand extends Command
{
    public function handle(): int
    {
        $query = N8nEndpoint::query();

        if ($this->option('active')) {
            $query->active();
        }

        $endpoints = $query->get();

        if ($endpoints->isEmpty()) {
            $this->info('No endpoints found.');

            return self::SUCCESS;
        }

        $this->table(
            ['Slug', 'Handler', 'Queue', 'Active', 'URL'],
            $endpoints->map(fn($e) => [
                $e->slug,
                class_basename($e->handler_class),
                $e->queue,
                $e->is_active ? '✅' : '❌',
                $e->inboundUrl(),
            ])
        );

        return self::SUCCESS;
    }
}
