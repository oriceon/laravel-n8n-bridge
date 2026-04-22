<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands\Credential;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Models\N8nCredential;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Create a new n8n credential bundle and generate its first API key.
 *
 * Usage:
 *   php artisan n8n:credential:create "Production" --instance=default
 *   php artisan n8n:credential:create "Staging" --instance=staging --ips=203.0.113.1
 */
#[AsCommand(name: 'n8n:credential:create')]
#[Signature('n8n:credential:create
        {name              : Human-readable name, e.g. "Production"}
        {--instance=default : n8n instance key from config}
        {--description=     : Optional description}
        {--ips=             : Allowed IPs, comma-separated (default: all)}')]
#[Description('Create a new n8n credential bundle and generate its API key')]
final class CredentialCreateCommand extends Command
{
    public function handle(): int
    {
        $ips = $this->option('ips')
            ? array_map('trim', explode(',', $this->option('ips')))
            : null;

        $credential = N8nCredential::create([
            'name'         => $this->argument('name'),
            'description'  => $this->option('description'),
            'n8n_instance' => $this->option('instance'),
            'is_active'    => true,
            'allowed_ips'  => $ips,
        ]);

        [$plaintext] = $credential->generateKey();

        $this->info('✅ Credential created!');
        $this->newLine();
        $this->line("  ID:       {$credential->id}");
        $this->line("  Name:     {$credential->name}");
        $this->line("  Instance: {$credential->n8n_instance}");

        if ($ips) {
            $this->line('  IPs:      ' . implode(', ', $ips));
        }

        $this->newLine();
        $this->warn('  🔑 API Key (copy now — shown once):');
        $this->line("     {$plaintext}");
        $this->newLine();
        $this->line('  In n8n: Personal → Credentials → Create credential → Header Auth');
        $this->line('           Name:  X-N8N-Key');
        $this->line("           Value: {$plaintext}");
        $this->newLine();
        $this->line('  Use this single credential on ALL HTTP Request nodes that');
        $this->line('  call /n8n/in, /n8n/tools, or /n8n/queue/progress.');

        return self::SUCCESS;
    }
}
