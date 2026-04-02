<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands\Credential;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Models\N8nCredential;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * List all registered n8n credential bundles.
 *
 *   php artisan n8n:credential:list
 */
#[AsCommand(name: 'n8n:credential:list')]
#[Signature('n8n:credential:list')]
#[Description('List all registered n8n credential bundles')]
final class CredentialListCommand extends Command
{
    public function handle(): int
    {
        $credentials = N8nCredential::with(['apiKeys', 'inboundEndpoints', 'tools'])
            ->orderBy('name')
            ->get();

        if ($credentials->isEmpty()) {
            $this->info('No credentials registered.');
            $this->line('  Run: php artisan n8n:credential:create "My n8n Instance"');

            return self::SUCCESS;
        }

        $this->table(
            ['ID (short)', 'Name', 'Instance', 'Active keys', 'Inbound', 'Tools', 'IPs', 'Status'],
            $credentials->map(fn(N8nCredential $c) => [
                substr($c->uuid, 0, 8) . '...',
                $c->name,
                $c->n8n_instance,
                $c->apiKeys->whereIn('status', ['active', 'grace'])->count(),
                $c->inboundEndpoints->count(),
                $c->tools->count(),
                $c->allowed_ips ? implode(',', $c->allowed_ips) : 'any',
                $c->is_active ? '✅' : '❌',
            ])
        );

        return self::SUCCESS;
    }
}
