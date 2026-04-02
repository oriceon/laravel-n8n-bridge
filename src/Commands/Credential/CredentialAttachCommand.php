<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands\Credential;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Models\N8nCredential;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Oriceon\N8nBridge\Models\N8nTool;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Attach a credential to one or more inbound endpoints or tools.
 *
 * Usage:
 *   php artisan n8n:credential:attach {credential-id} --inbound=invoice-paid
 *   php artisan n8n:credential:attach {credential-id} --tool=invoices --tool=contacts
 *   php artisan n8n:credential:attach {credential-id} --all
 */
#[AsCommand(name: 'n8n:credential:attach')]
#[Signature('n8n:credential:attach
        {id             : Credential ID}
        {--inbound=*    : Inbound endpoint slug(s) to attach}
        {--tool=*       : Tool name(s) to attach}
        {--all          : Attach this credential to ALL inbound endpoints and tools}')]
#[Description('Attach a credential to inbound endpoints and tools')]
final class CredentialAttachCommand extends Command
{
    public function handle(): int
    {
        $credential = N8nCredential::where('id', $this->argument('id'))->first();

        if ($credential === null) {
            $this->error("Credential [{$this->argument('id')}] not found.");

            return self::FAILURE;
        }

        $attached = 0;

        if ($this->option('all')) {
            $attached += N8nEndpoint::query()->update(['credential_id' => $credential->id]);
            $attached += N8nTool::query()->update(['credential_id' => $credential->id]);
            $this->info("✅ Credential [{$credential->name}] attached to all endpoints and tools ({$attached} total).");

            return self::SUCCESS;
        }

        foreach ($this->option('inbound') as $slug) {
            $count = N8nEndpoint::where('slug', $slug)->update(['credential_id' => $credential->id]);

            if ($count === 0) {
                $this->warn("  Inbound endpoint [{$slug}] not found.");
            }
            else {
                $this->line("  ✅ Inbound [{$slug}] attached.");
                ++$attached;
            }
        }

        foreach ($this->option('tool') as $name) {
            $count = N8nTool::where('name', $name)->update(['credential_id' => $credential->id]);

            if ($count === 0) {
                $this->warn("  Tool [{$name}] not found.");
            }
            else {
                $this->line("  ✅ Tool [{$name}] attached.");
                ++$attached;
            }
        }

        if ($attached === 0) {
            $this->warn('Nothing was attached. Use --inbound=, --tool=, or --all.');

            return self::FAILURE;
        }

        $this->info("✅ {$attached} item(s) attached to credential [{$credential->name}].");

        return self::SUCCESS;
    }
}
