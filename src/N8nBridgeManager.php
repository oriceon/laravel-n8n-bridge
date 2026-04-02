<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Oriceon\N8nBridge\Client\N8nApiClient;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nWorkflow;
use Oriceon\N8nBridge\Outbound\N8nOutboundDispatcher;
use Oriceon\N8nBridge\Queue\QueueManager;
use Oriceon\N8nBridge\Stats\StatsManager;

/**
 * Main manager — accessed via N8nBridge facade.
 *
 * @example
 *   N8nBridge::trigger('my-workflow', ['order_id' => 1])
 *   N8nBridge::stats()->forWorkflow($wf)->lastDays(7)->get()
 *   N8nBridge::client()->listWorkflows()
 */
final class N8nBridgeManager
{
    /** @var array<string, N8nApiClient> */
    private array $clients = [];

    public function __construct(
        private readonly N8nOutboundDispatcher $dispatcher,
        private readonly StatsManager $stats,
    ) {
    }

    /**
     * Trigger an outbound n8n workflow.
     *
     * @param  N8nWorkflow|string  $workflow  Model or workflow name
     */
    public function trigger(
        N8nWorkflow|string $workflow,
        array $payload = [],
        bool $async = true,
    ): N8nDelivery {
        if (is_string($workflow)) {
            $workflow = N8nWorkflow::query()
                ->active()
                ->where('name', $workflow)
                ->firstOrFail();
        }

        return $this->dispatcher->trigger($workflow, $payload, $async);
    }

    /**
     * Get stats manager (fluent query builder).
     */
    public function stats(): StatsManager
    {
        return $this->stats;
    }

    /**
     * Get the n8n API client for a specific instance.
     */
    public function client(string $instance = 'default'): N8nApiClient
    {
        if (isset($this->clients[$instance])) {
            return $this->clients[$instance];
        }

        $config = config("n8n-bridge.instances.{$instance}")
            ?? throw new \RuntimeException("n8n instance [{$instance}] not configured.");

        return $this->clients[$instance] = new N8nApiClient(
            baseUrl: $config['api_base_url'],
            apiKey: $config['api_key'],
            timeout: $config['timeout'] ?? 30,
            retryTimes: $config['retry_times'] ?? 3,
            retrySleepMs: $config['retry_sleep_ms'] ?? 500,
        );
    }

    /**
     * Sync a workflow from n8n API into the local DB.
     *
     * @throws ConnectionException
     * @throws RequestException
     */
    public function syncWorkflow(string $n8nId, string $instance = 'default'): N8nWorkflow
    {
        $data = $this->client($instance)->getWorkflow($n8nId);

        return N8nWorkflow::updateOrCreate(
            ['n8n_id' => $n8nId, 'n8n_instance' => $instance],
            [
                'name'           => $data['name'],
                'is_active'      => $data['active'] ?? false,
                'tags'           => collect($data['tags'] ?? [])->pluck('name')->toArray(),
                'nodes'          => $data['nodes'] ?? null,
                'last_synced_at' => now(),
            ]
        );
    }

    /**
     * Check health of a n8n instance.
     */
    public function health(string $instance = 'default'): bool
    {
        return $this->client($instance)->healthz();
    }

    /**
     * Access the DB queue manager.
     *
     * @example
     *   N8nBridge::queue()->dispatch('invoice-paid', $payload);
     *   N8nBridge::queue()->dispatchMany('invoice-reminder', $invoices);
     *   N8nBridge::queue()->stats();
     */
    public function queue(): QueueManager
    {
        return app(QueueManager::class);
    }
}
