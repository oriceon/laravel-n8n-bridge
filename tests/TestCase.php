<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Bridge\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use Oriceon\N8nBridge\N8nBridgeServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [
            N8nBridgeServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // Required for the `encrypted` cast used on N8nWorkflow.auth_key
        $app['config']->set('app.key', 'base64:' . base64_encode(str_repeat('a', 32)));

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver'   => 'sqlite',
            'database' => ':memory:',
            'prefix'   => '',
        ]);

        $app['config']->set('n8n-bridge.table_prefix', 'n8n');
        $app['config']->set('n8n-bridge.instances.default', [
            'api_base_url'     => 'http://n8n.test:5678',
            'api_key'          => 'test-api-key',
            'webhook_base_url' => 'http://n8n.test:5678/webhook',
            'timeout'          => 5,
        ]);
        $app['config']->set('n8n-bridge.notifications.enabled', false);
        $app['config']->set('n8n-bridge.outbound.listen_events', false);
        $app['config']->set('queue.default', 'sync');
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(
            __DIR__ . '/../database/migrations'
        );
    }
}
