<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Oriceon\N8nBridge\Facades\N8nBridge;
use Oriceon\N8nBridge\Models\N8nWorkflow;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'n8n:workflows:sync')]
#[Signature('n8n:workflows:sync {--instance=default}')]
#[Description('Sync workflows from n8n instance into local DB')]
final class WorkflowsSyncCommand extends Command
{
    /**
     * @throws RequestException
     * @throws ConnectionException
     */
    public function handle(): int
    {
        $instance = $this->option('instance');
        $this->info("Syncing workflows from [{$instance}]...");

        $workflows = N8nBridge::client($instance)->listWorkflows(['limit' => 250]);
        $synced    = 0;

        foreach ($workflows['data'] ?? [] as $wf) {
            N8nWorkflow::updateOrCreate(
                ['n8n_id' => $wf['id'], 'n8n_instance' => $instance],
                [
                    'name'           => $wf['name'],
                    'webhook_path'   => $wf['nodes'][0]['webhookId'] ?? null,
                    'http_method'    => $wf['nodes'][0]['parameters']['httpMethod'] ?? 'GET',
                    'is_active'      => $wf['active'] ?? false,
                    'tags'           => collect($wf['tags'] ?? [])->pluck('name')->toArray(),
                    'meta'           => (isset($wf['meta']) ?? is_array($wf['meta']) ? $wf['meta'] : null),
                    'last_synced_at' => now(),
                    'created_at'     => $wf['createdAt'],
                    'updated_at'     => $wf['updatedAt'],
                ]
            );

            ++$synced;
        }

        $this->info("✅ Synced {$synced} workflows.");

        return self::SUCCESS;
    }
}
