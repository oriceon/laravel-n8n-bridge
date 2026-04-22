<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Models\N8nApiKey;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'n8n:endpoint:rotate')]
#[Signature('n8n:endpoint:rotate {slug} {--grace=300 : Grace period in seconds for old key}')]
#[Description('Rotate the API key for an endpoint (with grace period)')]
final class EndpointRotateCommand extends Command
{
    public function handle(): int
    {
        $endpoint = N8nEndpoint::where('slug', $this->argument('slug'))->firstOrFail();
        $grace = (int) $this->option('grace');

        $credentials = $endpoint->credentials()->get();

        if ($credentials->isEmpty()) {
            $this->error("Endpoint [{$endpoint->slug}] has no credentials attached. Attach one first: n8n:credential:attach");

            return self::FAILURE;
        }

        if ($credentials->count() > 1) {
            $this->error("Endpoint [{$endpoint->slug}] has multiple credentials attached. Use: php artisan n8n:credential:rotate {uuid}");

            return self::FAILURE;
        }

        $credential = $credentials->first();

        // Put all active keys on this credential into a grace period
        N8nApiKey::query()
            ->where('credential_id', $credential->id)
            ->where('status', 'active')
            ->get()
            ->each(fn ($k) => $k->startGracePeriod($grace));

        [$plaintext] = N8nApiKey::generate($credential->id, 'artisan:rotate');

        $this->info("✅ New API key generated. Old key valid for {$grace}s.");
        $this->warn("🔑 New key: {$plaintext}");

        return self::SUCCESS;
    }
}
