<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Models\N8nApiKey;
use Oriceon\N8nBridge\Models\N8nCredential;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Create a new inbound endpoint with a fresh API key.
 *
 * Usage:
 *   php artisan n8n:endpoint:create invoice-paid \
 *     --handler="App\N8n\InvoicePaidHandler" \
 *     --queue=high
 */
#[AsCommand(name: 'n8n:endpoint:create')]
#[Signature('n8n:endpoint:create
        {slug : URL slug (e.g. invoice-paid → /n8n/in/invoice-paid)}
        {--handler= : Fully-qualified handler class}
        {--queue=default : Queue name for processing}
        {--rate-limit=60 : Max requests per minute}
        {--max-attempts=3 : Max retry attempts}')]
#[Description('Create an inbound endpoint with API key')]
final class EndpointCreateCommand extends Command
{
    public function handle(): int
    {
        $slug = $this->argument('slug');
        $handler = $this->option('handler');

        if ($handler === null) {
            $this->error('--handler is required.');

            return self::FAILURE;
        }

        // Create a dedicated credential for this endpoint (n8n auth bundle)
        $credential = N8nCredential::create([
            'name' => $slug,
            'n8n_instance' => 'default',
            'is_active' => true,
        ]);

        // Create endpoint and attach the credential via pivot
        $endpoint = N8nEndpoint::create([
            'slug' => $slug,
            'handler_class' => $handler,
            'queue' => $this->option('queue'),
            'rate_limit' => (int) $this->option('rate-limit'),
            'max_attempts' => (int) $this->option('max-attempts'),
        ]);

        $endpoint->credentials()->attach($credential->id);

        // Generate API key for the credential
        [$plaintext] = N8nApiKey::generate($credential->id, 'artisan');

        $this->newLine();
        $this->info('✅ Endpoint created successfully!');
        $this->newLine();

        $this->table(
            ['Field', 'Value'],
            [
                ['Slug',        $slug],
                ['URL',         $endpoint->inboundUrl()],
                ['Handler',     $handler],
                ['Queue',       $endpoint->queue],
                ['Rate Limit',  $endpoint->rate_limit.'/min'],
            ]
        );

        $this->newLine();

        $this->warn('🔑 API Key (copy now — shown once):');

        $this->line("   <fg=yellow>{$plaintext}</>");

        $this->newLine();

        $this->line('Add to n8n HTTP Request node header: X-N8N-Key: '.$plaintext);

        return self::SUCCESS;
    }
}
