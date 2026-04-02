<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands\Credential;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Auth\CredentialAuthService;
use Oriceon\N8nBridge\Models\N8nCredential;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Rotate the API key for a credential (zero-downtime rotation).
 *
 * The old key enters a grace period during which it remains valid.
 * Update the credential in n8n within the grace window.
 *
 * php artisan n8n:credential:rotate {id} --grace=300
 */
#[AsCommand(name: 'n8n:credential:rotate')]
#[Signature('n8n:credential:rotate
        {id             : Credential UUID (full or first 8 chars)}
        {--grace=300    : Seconds the old key stays valid}')]
#[Description('Rotate the API key for a credential with a grace period')]
final class CredentialRotateCommand extends Command
{
    /**
     * @param CredentialAuthService $auth
     * @return int
     */
    public function handle(CredentialAuthService $auth): int
    {
        $id = (string) $this->argument('id');

        $credential = strlen($id) === 36
            ? N8nCredential::where('uuid', $id)->first()
            : N8nCredential::where('uuid', 'like', $id . '%')->first();

        if ($credential === null) {
            $this->error("Credential [{$id}] not found.");

            return self::FAILURE;
        }

        $grace       = (int) $this->option('grace');
        [$plaintext] = $credential->rotateKey($grace);

        // Invalidate cached auth for an old key — a new key will be cached on first use.
        // Note: we can't invalidate by old plaintext here since we don't store it.
        // Cache TTL (60s) ensures old entries expire naturally.

        $this->info("✅ Key rotated for credential [{$credential->name}]");
        $this->newLine();
        $this->warn('  🔑 New API Key (copy now — shown once):');
        $this->line("     {$plaintext}");
        $this->newLine();
        $this->line("  Old key valid for {$grace} seconds. Update n8n credential within that window.");
        $this->line('  In n8n: Personal → Credentials → find "X-N8N-Key" → update value.');

        return self::SUCCESS;
    }
}
