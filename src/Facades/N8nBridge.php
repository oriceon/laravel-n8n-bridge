<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Facades;

use Illuminate\Support\Facades\Facade;
use Oriceon\N8nBridge\Client\N8nApiClient;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nWorkflow;
use Oriceon\N8nBridge\N8nBridgeManager;
use Oriceon\N8nBridge\Stats\StatsManager;

/**
 * @method static N8nDelivery   trigger(string|N8nWorkflow $workflow, array $payload = [], bool $async = true)
 * @method static StatsManager  stats()
 * @method static N8nApiClient  client(string $instance = 'default')
 * @method static N8nWorkflow   syncWorkflow(string $n8nId, string $instance = 'default')
 * @method static bool  health(string $instance = 'default')
 *
 * @see N8nBridgeManager
 */
final class N8nBridge extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return N8nBridgeManager::class;
    }
}
