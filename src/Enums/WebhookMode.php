<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Enums;

/**
 * Controls which n8n webhook URL variant is used when triggering a workflow.
 *
 * n8n exposes two webhook variants per workflow:
 *   - /webhook/{path}       — active (production) webhook
 *   - /webhook-test/{path}  — test webhook (only works while the workflow
 *                             is open in the n8n editor and listening)
 *
 * | Mode       | URL used          | When to use                              |
 * |------------|-------------------|------------------------------------------|
 * | Auto       | env-based         | Default — test in dev, prod in production|
 * | Production | /webhook          | Always hit the active workflow            |
 * | Test       | /webhook-test     | Always hit the test listener              |
 */
enum WebhookMode: string
{
    /**
     * Automatically select based on APP_ENV:
     *   - production → /webhook
     *   - any other env → /webhook-test
     */
    case Auto = 'auto';

    /**
     * Always use the production webhook URL (/webhook).
     * The workflow must be active in n8n.
     */
    case Production = 'production';

    /**
     * Always use the test webhook URL (/webhook-test).
     * The n8n workflow must be open in the editor and listening.
     */
    case Test = 'test';

    public function label(): string
    {
        return match ($this) {
            self::Auto       => 'Auto (env-based)',
            self::Production => 'Production (/webhook)',
            self::Test       => 'Test (/webhook-test)',
        };
    }
}
