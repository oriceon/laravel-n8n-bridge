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
 * Attach (or detach) a credential to one or more inbound endpoints or tools.
 *
 * Multiple credentials can be attached to the same endpoint/tool.
 * An endpoint/tool with no credentials attached rejects all requests (401).
 *
 * Usage:
 *   php artisan n8n:credential:attach {id} --inbound=invoice-paid
 *   php artisan n8n:credential:attach {id} --tool=invoices --tool=contacts
 *   php artisan n8n:credential:attach {id} --all
 *
 *   # Detach:
 *   php artisan n8n:credential:attach {id} --detach-inbound=invoice-paid
 *   php artisan n8n:credential:attach {id} --detach-tool=invoices
 *   php artisan n8n:credential:attach {id} --detach-all
 */
#[AsCommand(name: 'n8n:credential:attach')]
#[Signature('n8n:credential:attach
        {id                 : Credential ID}
        {--inbound=*        : Inbound endpoint slug(s) to attach}
        {--tool=*           : Tool name(s) to attach}
        {--all              : Attach this credential to ALL inbound endpoints and tools}
        {--detach-inbound=* : Inbound endpoint slug(s) to detach}
        {--detach-tool=*    : Tool name(s) to detach}
        {--detach-all       : Detach this credential from ALL inbound endpoints and tools}')]
#[Description('Attach or detach a credential to/from inbound endpoints and tools')]
final class CredentialAttachCommand extends Command
{
    public function handle(): int
    {
        $credential = N8nCredential::where('id', $this->argument('id'))->first();

        if ($credential === null) {
            $this->error("Credential [{$this->argument('id')}] not found.");

            return self::FAILURE;
        }

        $changed = 0;

        // ── Detach all ────────────────────────────────────────────────────────
        if ($this->option('detach-all')) {
            $endpointCount = $credential->inboundEndpoints()->count();
            $toolCount = $credential->tools()->count();

            $credential->inboundEndpoints()->detach();
            $credential->tools()->detach();

            $changed = $endpointCount + $toolCount;
            $this->info("✅ Credential [{$credential->name}] detached from all endpoints and tools ({$changed} total).");

            return self::SUCCESS;
        }

        // ── Attach all ────────────────────────────────────────────────────────
        if ($this->option('all')) {
            N8nEndpoint::query()->each(function (N8nEndpoint $ep) use ($credential, &$changed): void {
                $ep->credentials()->syncWithoutDetaching([$credential->id]);
                $changed++;
            });

            N8nTool::query()->each(function (N8nTool $tool) use ($credential, &$changed): void {
                $tool->credentials()->syncWithoutDetaching([$credential->id]);
                $changed++;
            });

            $this->info("✅ Credential [{$credential->name}] attached to all endpoints and tools ({$changed} total).");

            return self::SUCCESS;
        }

        // ── Selective attach ──────────────────────────────────────────────────
        foreach ($this->option('inbound') as $slug) {
            $endpoint = N8nEndpoint::where('slug', $slug)->first();

            if ($endpoint === null) {
                $this->warn("  Inbound endpoint [{$slug}] not found.");
            } else {
                $endpoint->credentials()->syncWithoutDetaching([$credential->id]);
                $this->line("  ✅ Inbound [{$slug}] attached.");
                $changed++;
            }
        }

        foreach ($this->option('tool') as $name) {
            $tool = N8nTool::where('name', $name)->first();

            if ($tool === null) {
                $this->warn("  Tool [{$name}] not found.");
            } else {
                $tool->credentials()->syncWithoutDetaching([$credential->id]);
                $this->line("  ✅ Tool [{$name}] attached.");
                $changed++;
            }
        }

        // ── Selective detach ──────────────────────────────────────────────────
        foreach ($this->option('detach-inbound') as $slug) {
            $endpoint = N8nEndpoint::where('slug', $slug)->first();

            if ($endpoint === null) {
                $this->warn("  Inbound endpoint [{$slug}] not found.");
            } else {
                $endpoint->credentials()->detach($credential->id);
                $this->line("  ✅ Inbound [{$slug}] detached.");
                $changed++;
            }
        }

        foreach ($this->option('detach-tool') as $name) {
            $tool = N8nTool::where('name', $name)->first();

            if ($tool === null) {
                $this->warn("  Tool [{$name}] not found.");
            } else {
                $tool->credentials()->detach($credential->id);
                $this->line("  ✅ Tool [{$name}] detached.");
                $changed++;
            }
        }

        if ($changed === 0) {
            $this->warn('Nothing was attached or detached. Use --inbound=, --tool=, --all, --detach-inbound=, --detach-tool=, or --detach-all.');

            return self::FAILURE;
        }

        $this->info("✅ {$changed} item(s) updated for credential [{$credential->name}].");

        return self::SUCCESS;
    }
}
