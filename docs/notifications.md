![Laravel N8N Bridge](images/banner.png)

# 🔔 Notifications & Alerts

← [Back to README](../README.md)

The package sends alerts automatically on critical events. All channels are optional and independently configurable.

---

## Automatic alerts

| Trigger | Severity | Default channels |
|---|---|---|
| Delivery exhausts all retries (DLQ) | Error | All configured |
| Circuit breaker opens | Critical | All configured |
| Error rate exceeds threshold | Warning | All configured |
| Queue job goes Dead | Error | All configured |

---

## Configuration

```env
N8N_BRIDGE_NOTIFY_ENABLED=true
N8N_BRIDGE_NOTIFY_CHANNELS=slack,mail   # comma-separated list

# Mail
N8N_BRIDGE_NOTIFY_MAIL_TO=ops@myapp.com

# Slack incoming webhook
N8N_BRIDGE_NOTIFY_SLACK_WEBHOOK=https://hooks.slack.com/services/T.../B.../xxx
N8N_BRIDGE_NOTIFY_SLACK_CHANNEL=#n8n-alerts

# Discord incoming webhook
N8N_BRIDGE_NOTIFY_DISCORD_WEBHOOK=https://discord.com/api/webhooks/xxx/yyy

# Microsoft Teams incoming webhook
N8N_BRIDGE_NOTIFY_TEAMS_WEBHOOK=https://mycompany.webhook.office.com/webhookb2/xxx

# Generic HTTP webhook (JSON POST)
N8N_BRIDGE_NOTIFY_WEBHOOK_URL=https://myapp.com/hooks/n8n-alerts

# Error rate threshold (percent, triggers high-error-rate alert)
N8N_BRIDGE_NOTIFY_ERROR_RATE=20.0
```

All channels with a missing URL/address are silently skipped.

---

## Channel payloads

### Slack

Sends a rich `Block Kit` message with severity colour, workflow name, error message, and context fields.

### Discord

Sends an embed with colour-coded severity (`red` = critical, `orange` = error, `yellow` = warning, `blue` = info).

### Microsoft Teams

Sends a `MessageCard` with section facts (workflow, error, context).

### Generic Webhook

```json
POST <your-url>
Content-Type: application/json

{
  "title":        "Delivery Dead — invoice-paid",
  "message":      "Job [uuid] exhausted 3 attempts...",
  "severity":     "error",
  "workflow_name": "invoice-paid",
  "delivery_id":  "uuid",
  "context":      { "attempts": 3, "http_status": 500 },
  "timestamp":    "2026-03-22T10:00:00Z"
}
```

---

## Send custom notifications

```php
use Oriceon\N8nBridge\Notifications\NotificationDispatcher;
use Oriceon\N8nBridge\Enums\AlertSeverity;

$dispatcher = app(NotificationDispatcher::class);

$dispatcher->notifyCustom(
    title:        'Sync completed',
    message:      '1,247 contacts synced with HubSpot.',
    severity:     AlertSeverity::Info,
    workflowName: 'hubspot-sync',
    context:      ['synced' => 1247, 'skipped' => 3, 'duration_ms' => 4820],
);
```

### AlertSeverity levels

| Value | Color | Use for |
|---|---|---|
| `Info` | Blue | Informational notices |
| `Warning` | Yellow | Degraded performance, threshold approaching |
| `Error` | Orange | Single component failure |
| `Critical` | Red | System-wide failure, data loss risk |

---

## Listen to notification events

You can hook into the process before notifications are dispatched:

```php
use Oriceon\N8nBridge\Events\N8nDeliveryDeadEvent;
use Oriceon\N8nBridge\Events\N8nCircuitBreakerOpenedEvent;

// Add to EventServiceProvider
protected $listen = [
    N8nDeliveryDeadEvent::class => [
        \App\Listeners\CreateSupportTicketOnDeadDelivery::class,
    ],
    N8nCircuitBreakerOpenedEvent::class => [
        \App\Listeners\PageOnCallEngineer::class,
    ],
];
```

---

## Disable notifications per environment

```php
// In a test or staging environment
config(['n8n-bridge.notifications.enabled' => false]);
```

Or in `config/n8n-bridge.php`:

```php
'notifications' => [
    'enabled' => (bool) env('N8N_BRIDGE_NOTIFY_ENABLED', true),
    // ...
],
```
