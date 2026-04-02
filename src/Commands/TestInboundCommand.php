<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Oriceon\N8nBridge\DTOs\N8nPayload;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'n8n:test-inbound')]
#[Signature('n8n:test-inbound {slug} {--payload= : JSON payload} {--dry-run : Validate only, do not execute}')]
#[Description('Test an inbound endpoint locally')]
final class TestInboundCommand extends Command
{
    /**
     * @throws \JsonException
     * @throws \Exception
     */
    public function handle(): int
    {
        $endpoint = N8nEndpoint::where('slug', $this->argument('slug'))->firstOrFail();
        $raw      = json_decode($this->option('payload') ?? '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
        $payload  = N8nPayload::fromRequest($raw);
        $handler  = app($endpoint->handler_class);
        $rules    = $handler->rules();

        if ( ! empty($rules)) {
            Validator::make($payload->all(), $rules, $handler->messages())->validate();
            $this->info('✅ Validation passed.');
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run complete — handler not executed.');

            return self::SUCCESS;
        }

        $handler->handle($payload);

        $this->info('✅ Handler executed successfully.');

        return self::SUCCESS;
    }
}
