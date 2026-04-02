<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Table Prefix
    |--------------------------------------------------------------------------
    | Tables will be named: {prefix}__{entity}
    | Default: n8n__workflows__lists, n8n__endpoints__lists, n8n__deliveries__lists ...
    | Change to e.g. 'automation' → automation__workflows__lists etc.
     */
    'table_prefix' => env('N8N_BRIDGE_TABLE_PREFIX', 'n8n'),

    /*
    |--------------------------------------------------------------------------
    | n8n Instances
    |--------------------------------------------------------------------------
    | Multiple n8n instances are supported.
    | Each workflow references its instance by key.
     */
    'instances' => [
        'default' => [
            'api_base_url'     => env('N8N_BRIDGE_N8N_DEFAULT_API_BASE_URL', 'http://localhost:5678'),
            'api_key'          => env('N8N_BRIDGE_N8N_DEFAULT_API_KEY'),
            'webhook_base_url' => env('N8N_BRIDGE_N8N_DEFAULT_WEBHOOK_BASE_URL', 'http://localhost:5678/webhook'),

            // Optional: explicit test webhook URL.
            // When null, auto-derived by replacing /webhook → /webhook-test
            // e.g., https://n8n.example.com/webhook → https://n8n.example.com/webhook-test
            'webhook_test_base_url' => env('N8N_BRIDGE_N8N_DEFAULT_WEBHOOK_TEST_BASE_URL'),

            'timeout'        => (int) env('N8N_BRIDGE_N8N_DEFAULT_TIMEOUT', 30),
            'retry_times'    => (int) env('N8N_BRIDGE_N8N_DEFAULT_RETRY_TIMES', 3),
            'retry_sleep_ms' => (int) env('N8N_BRIDGE_N8N_DEFAULT_RETRY_SLEEP_MS', 500),
        ],
        // 'staging' => [
        //     'api_base_url'          => env('N8N_BRIDGE_N8N_STAGING_API_BASE_URL'),
        //     'api_key'               => env('N8N_BRIDGE_N8N_STAGING_API_KEY'),
        //     'webhook_base_url'      => env('N8N_BRIDGE_N8N_STAGING_WEBHOOK_BASE_URL'),
        //     'webhook_test_base_url' => env('N8N_BRIDGE_N8N_STAGING_WEBHOOK_TEST_BASE_URL'),
        // ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Inbound Webhook Routes
    |--------------------------------------------------------------------------
     */
    'inbound' => [
        'route_prefix' => env('N8N_BRIDGE_INBOUND_PREFIX', 'n8n/in'),
        'middleware'   => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Outbound Settings
    |--------------------------------------------------------------------------
     */
    'outbound' => [
        'queue'         => env('N8N_BRIDGE_OUTBOUND_QUEUE', 'default'),
        'timeout'       => (int) env('N8N_BRIDGE_OUTBOUND_TIMEOUT', 30),
        'listen_events' => (bool) env('N8N_BRIDGE_LISTEN_EVENTS', true),

        // Global rate limit: max outbound requests per minute to n8n (0 = unlimited).
        // Per-workflow override: set `rate_limit` on the N8nWorkflow record.
        'rate_limit' => (int) env('N8N_BRIDGE_OUTBOUND_RATE_LIMIT', 0),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tools (Laravel → n8n nodes)
    |--------------------------------------------------------------------------
     */
    'tools' => [
        'route_prefix' => env('N8N_BRIDGE_TOOLS_PREFIX', 'n8n/tools'),
        'middleware'   => ['api'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Circuit Breaker
    |--------------------------------------------------------------------------
     */
    'circuit_breaker' => [
        'threshold'    => (int) env('N8N_BRIDGE_CB_THRESHOLD', 5), // failures before opening
        'cooldown_sec' => (int) env('N8N_BRIDGE_CB_COOLDOWN', 60), // seconds before half-open
    ],

    /*
    |--------------------------------------------------------------------------
    | Statistics
    |--------------------------------------------------------------------------
     */
    'stats' => [
        'retention_days' => (int) env('N8N_BRIDGE_STATS_RETENTION', 90),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notifications — failure alerts
    |--------------------------------------------------------------------------
    | channels: any combination of 'mail', 'slack', 'discord', 'teams', 'webhook'
    | Only channels with a configured URL/address will be active.
     */
    'notifications' => [
        'enabled' => (bool) env('N8N_BRIDGE_NOTIFY_ENABLED', true),

        'channels' => explode(',', env('N8N_BRIDGE_NOTIFY_CHANNELS', 'mail')),

        // Email
        'mail_to' => env('N8N_BRIDGE_NOTIFY_MAIL_TO'), // "ops@example.com"

        // Slack incoming webhook URL
        'slack_webhook' => env('N8N_BRIDGE_NOTIFY_SLACK_WEBHOOK'),
        'slack_channel' => env('N8N_BRIDGE_NOTIFY_SLACK_CHANNEL', '#n8n-alerts'),

        // Discord incoming webhook URL
        'discord_webhook' => env('N8N_BRIDGE_NOTIFY_DISCORD_WEBHOOK'),

        // Microsoft Teams incoming webhook URL
        'teams_webhook' => env('N8N_BRIDGE_NOTIFY_TEAMS_WEBHOOK'),

        // Generic HTTP webhook (JSON POST)
        'generic_webhook' => env('N8N_BRIDGE_NOTIFY_WEBHOOK_URL'),

        // Alert threshold: notify when the error rate exceeds X%
        'error_rate_threshold' => (float) env('N8N_BRIDGE_NOTIFY_ERROR_RATE', 20.0),
    ],

    /*
    |--------------------------------------------------------------------------
    | DB Queue — advanced workflow dispatch queue
    |--------------------------------------------------------------------------
    | Workers poll n8n__queue__jobs using SELECT FOR UPDATE SKIP LOCKED.
    | Run workers with: php artisan n8n:queue:work
    |
    | Supervisor example (3 workers: 1 critical, 1 normal, 1 bulk):
    | php artisan n8n:queue:work --queue=critical --sleep=1
    | php artisan n8n:queue:work --queue=high,normal --sleep=1
    | php artisan n8n:queue:work --queue=bulk --sleep=5
     */
    'queue' => [
        // Default queue name if not specified per-job
        'default_queue' => env('N8N_BRIDGE_QUEUE_DEFAULT', 'default'),

        // How many seconds a worker holds a lease before a job is considered stuck
        'lease_seconds' => (int) env('N8N_BRIDGE_QUEUE_LEASE', 150),

        // How many minutes before a claimed/running job is considered stuck
        'stuck_minutes' => (int) env('N8N_BRIDGE_QUEUE_STUCK_MINUTES', 10),

        // Automatically prune completed jobs via scheduler
        'auto_prune' => (bool) env('N8N_BRIDGE_QUEUE_AUTO_PRUNE', true),

        // Delete terminal jobs older than N days
        'prune_days' => (int) env('N8N_BRIDGE_QUEUE_PRUNE_DAYS', 30),

        // Log channel for worker output (null = default)
        'log_channel' => env('N8N_BRIDGE_QUEUE_LOG_CHANNEL', 'stack'),

        // ── Progress tracking ─────────────────────────────────────────────────
        // Route where n8n sends checkpoint updates
        'progress_route_prefix' => env('N8N_BRIDGE_QUEUE_PROGRESS_PREFIX', 'n8n/queue/progress'),
        'progress_middleware'   => ['api'],

        // Global API key for progress endpoint (all jobs share this key).
        // Alternative: use per-workflow endpoint API keys (automatic).
        // Set to null to use per-workflow key auth only.
        'progress_api_key' => env('N8N_BRIDGE_QUEUE_PROGRESS_KEY'),

        // Auto-delete checkpoints when a job completes successfully.
        // Set false to retain checkpoints for all jobs (increases storage).
        'delete_checkpoints_on_success' => (bool) env('N8N_BRIDGE_QUEUE_DELETE_CHECKPOINTS', true),

        // Delete done (successful) jobs automatically via the scheduler.
        // When true, done jobs are pruned after `done_jobs_prune_days` days,
        // which runs AFTER the nightly stats aggregation (00:05) to ensure
        // all queue stats are captured in n8n__stats__lists before deletion.
        // Set false to retain done jobs for the standard `prune_days` period.
        'delete_done_jobs' => (bool) env('N8N_BRIDGE_QUEUE_DELETE_DONE_JOBS', false),

        // How many days to keep done jobs when `delete_done_jobs` is true.
        // Default is 1 day — ensures the 00:05 stat aggregation has captured
        // all data before the 01:00 prune runs.
        'done_jobs_prune_days' => (int) env('N8N_BRIDGE_QUEUE_DONE_PRUNE_DAYS', 1),

        // ── Duration estimation ───────────────────────────────────────────────
        // Rolling EMA window: how many successful jobs to factor in.
        // Higher = smoother but slower to adapt. Lower = faster but noisier.
        'duration_sample_size' => (int) env('N8N_BRIDGE_QUEUE_DURATION_SAMPLES', 50),

        // Retry strategy delays (seconds) per priority level
        // Workers use exponential backoff by default; these set the base delay
        'retry_delays' => [
            'critical' => 10,
            'high'     => 15,
            'normal'   => 30,
            'low'      => 60,
            'bulk'     => 120,
        ],
    ],

];
