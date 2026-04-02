<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Auth\WebhookAuthService;
use Oriceon\N8nBridge\Enums\WebhookAuthType;
use Oriceon\N8nBridge\Models\N8nWorkflow;
use Random\RandomException;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Configure outbound authentication for a workflow (Laravel → n8n).
 *
 * Usage:
 *   php artisan n8n:workflow:auth-setup "My workflow" --type=header_token
 *   php artisan n8n:workflow:auth-setup "My workflow" --type=bearer
 *   php artisan n8n:workflow:auth-setup "My workflow" --type=hmac_sha256
 *   php artisan n8n:workflow:auth-setup "My workflow" --type=none
 */
#[AsCommand(name: 'n8n:workflow:auth-setup')]
#[Signature('n8n:workflow:auth-setup
        {workflow              : Workflow name or UUID}
        {--type=header_token   : Auth type: none, header_token, bearer, hmac_sha256}
        {--key=                : Use existing key instead of generating a new one}
        {--show                : Show the current auth config without changing it}')]
#[Description('Configure outbound authentication (Laravel → n8n) for a workflow')]
final class WorkflowAuthSetupCommand extends Command
{
    /**
     * @throws RandomException
     */
    public function handle(): int
    {
        $identifier = $this->argument('workflow');

        $workflow = N8nWorkflow::where('name', $identifier)
            ->orWhere('uuid', $identifier)
            ->first();

        if ($workflow === null) {
            $this->error("Workflow not found: {$identifier}");

            return self::FAILURE;
        }

        if ($this->option('show')) {
            $this->showCurrentConfig($workflow);

            return self::SUCCESS;
        }

        $typeValue = $this->option('type');
        $type      = WebhookAuthType::tryFrom($typeValue);

        if ($type === null) {
            $this->error("Invalid auth type: {$typeValue}");
            $this->line('Valid types: ' . implode(', ', array_column(WebhookAuthType::cases(), 'value')));

            return self::FAILURE;
        }

        if ($type === WebhookAuthType::None) {
            $workflow->update(['auth_type' => WebhookAuthType::None, 'auth_key' => null]);
            $this->info("✅ Authentication removed from [{$workflow->name}].");

            return self::SUCCESS;
        }

        // Use provided key or generate a secure random key (64-char hex = 256 bits)
        $plaintext = $this->option('key') ?: WebhookAuthService::generateKey();

        $workflow->update([
            'auth_type' => $type,
            'auth_key'  => $plaintext,
        ]);

        $this->info("✅ Outbound auth configured for [{$workflow->name}]");
        $this->newLine();
        $this->line("  Workflow: {$workflow->name}");
        $this->line("  Auth:     {$type->label()}");
        $this->newLine();
        $this->warn('  🔑 Key (copy now — shown once):');
        $this->line("     {$plaintext}");
        $this->newLine();
        $this->line('  In n8n: Personal → Credentials → Create credential → Header Auth');

        match ($type) {
            WebhookAuthType::HeaderToken => $this->line("    Name:  X-N8N-Workflow-Key\n    Value: {$plaintext}"),
            WebhookAuthType::Bearer      => $this->line("    Name:  Authorization\n    Value: Bearer {$plaintext}"),
            WebhookAuthType::HmacSha256  => $this->line("    Use a Code node to verify X-N8N-Signature.\n    See docs/webhook-auth.md for the verification snippet."),
            default                      => null,
        };

        return self::SUCCESS;
    }

    private function showCurrentConfig(N8nWorkflow $workflow): void
    {
        $type = $workflow->auth_type ?? WebhookAuthType::None;

        $this->line("  Workflow: {$workflow->name}");
        $this->line("  UUID:     {$workflow->uuid}");
        $this->line("  Auth:     {$type->label()}");
        $this->line('  Key set:  ' . ($workflow->auth_key ? 'yes (encrypted)' : 'no'));
    }
}
