<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Facades\N8nBridge;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'n8n:health')]
#[Signature('n8n:health {--instance=default}')]
#[Description('Check connectivity to n8n instance')]
final class HealthCommand extends Command
{
    public function handle(): int
    {
        $instance = $this->option('instance');
        $ok       = N8nBridge::health($instance);

        if ($ok) {
            $this->info("✅ n8n instance [{$instance}] is reachable.");

            return self::SUCCESS;
        }

        $this->error("❌ n8n instance [{$instance}] is unreachable.");

        return self::FAILURE;
    }
}
