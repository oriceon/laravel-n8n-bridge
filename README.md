![Laravel N8N Bridge](docs/images/banner.png)

# 🔗 laravel-n8n-bridge

**A full-featured, bidirectional Laravel 13+ bridge for n8n** — DB-driven workflows, secured outbound webhook, secured inbound webhook receiver, circuit breakers, delivery statistics, tools exposed to n8n, DB queue system with live progress tracking, and multi-channel failure notifications.

[![Latest Version](https://img.shields.io/packagist/v/oriceon/laravel-n8n-bridge.svg)](https://packagist.org/packages/oriceon/laravel-n8n-bridge)
[![PHP Version](https://img.shields.io/badge/PHP-8.5%2B-blue)](https://php.net)
[![Laravel](https://img.shields.io/badge/Laravel-13.x-red)](https://laravel.com)
[![License](https://img.shields.io/badge/license-MIT-green)](LICENSE.md)
[![Tests](https://img.shields.io/badge/tests-846%20passing-brightgreen)](https://pestphp.com)

---

## ✨ What this package does

Unlike other n8n packages for Laravel — which are simple outbound HTTP clients — `laravel-n8n-bridge` is a **complete bidirectional integration**:

| Feature | Other packages | **laravel-n8n-bridge** |
|---|:---:|:---:|
| Trigger workflow outbound | ✅ | ✅ |
| **Outbound auth** (token / bearer / HMAC-SHA256) | ❌ | ✅ |
| **Outbound rate limiting** (global + per-workflow) | ❌ | ✅ |
| **Webhook mode** (test / production / auto) | ❌ | ✅ |
| **Inbound webhook receiver** | ❌ | ✅ |
| **Webhook auth** (one key, all routes) | ❌ | ✅ |
| **Rotatable API keys** with grace period | ❌ | ✅ |
| **Circuit Breaker** per workflow | ❌ | ✅ |
| **DB-driven workflows** (not config files) | ❌ | ✅ |
| **DB Queue** with priority, batches, DLQ | ❌ | ✅ |
| **Live progress tracking** + broadcasting | ❌ | ✅ |
| **Estimated completion time** (EMA) | ❌ | ✅ |
| **Tool system** — GET/POST/PATCH/DELETE | ❌ | ✅ |
| **OpenAPI schema** auto-generated | ❌ | ✅ |
| **Daily statistics** with chart-ready data | ❌ | ✅ |
| **Notifications** Slack/Discord/Teams/Mail | ❌ | ✅ |
| **Idempotency** native | ❌ | ✅ |
| **DLQ + replay** | ❌ | ✅ |
| Artisan commands | ❌ | ✅ (20+) |
| Full Pest test suite | ❌ | ✅ (832 tests) |

---

## 📋 Requirements

- PHP **8.5+**
- Laravel **13.x**
- n8n (self-hosted or cloud)

---

## 🚀 Installation

```bash
composer require oriceon/laravel-n8n-bridge
php artisan vendor:publish --tag="n8n-bridge-config"
php artisan vendor:publish --tag="n8n-bridge-migrations"
php artisan migrate
```

---

## ⚙️ Configuration

If you want to publish the config or migrations, you can do so with:

```bash
php artisan vendor:publish --tag="n8n-bridge-config"
php artisan vendor:publish --tag="n8n-bridge-migrations"
```

Set .env variables

```env
# n8n instance (multiple supported via config)
N8N_BRIDGE_N8N_DEFAULT_API_BASE_URL=https://n8n.myapp.com
N8N_BRIDGE_N8N_DEFAULT_API_KEY=your-n8n-api-key
N8N_BRIDGE_N8N_DEFAULT_WEBHOOK_BASE_URL=https://n8n.myapp.com/webhook
# Optional: explicit test URL (null = auto-derived by replacing /webhook → /webhook-test)
N8N_BRIDGE_N8N_DEFAULT_WEBHOOK_TEST_BASE_URL=https://n8n.myapp.com/webhook-test

# Table prefix (default: n8n → n8n__workflows__lists etc.)
N8N_BRIDGE_TABLE_PREFIX=n8n

# Notifications
N8N_BRIDGE_NOTIFY_ENABLED=true
N8N_BRIDGE_NOTIFY_CHANNELS=slack,mail
N8N_BRIDGE_NOTIFY_SLACK_WEBHOOK=https://hooks.slack.com/services/xxx
N8N_BRIDGE_NOTIFY_DISCORD_WEBHOOK=https://discord.com/api/webhooks/xxx
N8N_BRIDGE_NOTIFY_MAIL_TO=ops@myapp.com
N8N_BRIDGE_NOTIFY_ERROR_RATE=20.0

# Outbound rate limiting (0 = unlimited)
N8N_BRIDGE_OUTBOUND_RATE_LIMIT=0

# Queue system
N8N_BRIDGE_QUEUE_DEFAULT=default
N8N_BRIDGE_QUEUE_DELETE_CHECKPOINTS=true
N8N_BRIDGE_QUEUE_DURATION_SAMPLES=50
```

---

## 🔑 Authentication

All `/n8n/*` routes require an `X-N8N-Key` header. One credential key works across all modules — inbound, tools, and queue progress.

```bash
# Create a credential + key
php artisan n8n:credential:create "Production" --instance=default
# → outputs: n8br_sk_a3f9b2c1...

# In n8n: Personal → Credentials → Create credential → Header Auth
# Name:  X-N8N-Key
# Value: n8br_sk_a3f9b2c1...
```

See [docs/credentials.md](docs/credentials.md) for rotation, IP whitelisting, grace periods, and multi-webhook setups.

---

## 📥 Inbound — receive data from n8n

### 1. Create an endpoint

```bash
php artisan n8n:endpoint:create invoice-paid \
  --handler="App\N8n\InvoicePaidHandler" \
  --queue=high
```

### 2. Write the handler

```php
namespace App\N8n;

use Oriceon\N8nBridge\DTOs\N8nPayload;
use Oriceon\N8nBridge\Inbound\N8nInboundHandler;

final class InvoicePaidHandler extends N8nInboundHandler
{
    public function handle(N8nPayload $payload): void
    {
        $invoice = Invoice::findOrFail($payload->required('invoice_id'));
        $invoice->update([
            'status'  => 'paid',
            'paid_at' => $payload->getCarbon('paid_at'),
        ]);
    }

    public function rules(): array
    {
        return [
            'invoice_id' => 'required|integer',
            'paid_at'    => 'required|date',
        ];
    }
}
```

### 3. Configure in n8n

```
URL:    POST https://myapp.com/n8n/in/invoice-paid
Header: X-N8N-Key: n8br_sk_...
Header: X-N8N-Execution-Id: {{ $execution.id }}
```

### Security pipeline

Every request passes through 6 layers automatically:

```
POST /n8n/in/{slug}
        │
        ▼  1. RateLimiter      → 429 if exceeded
        ▼  2. ApiKeyVerifier   → 401 if key invalid
        ▼  3. IpWhitelist      → 403 if IP not allowed
        ▼  4. HmacVerifier     → 401 if signature mismatch
        ▼  5. IdempotencyCheck → 200 skip if already processed
        ▼  6. PayloadStore     → persist delivery to DB
        ▼  ACK 202 + dispatch job to queue
```

See [docs/inbound.md](docs/inbound.md) for HMAC, rate limits, IP whitelist, DLQ, and handler validation.

---

## 📤 Outbound — trigger n8n from Laravel

```php
use Oriceon\N8nBridge\Facades\N8nBridge;

// By workflow name
N8nBridge::trigger('order-shipped', [
    'order_id'   => $order->id,
    'shipped_at' => now()->toIso8601String(),
]);

// Sync (waits for HTTP response)
N8nBridge::trigger($workflow, $payload, async: false);
```

### Automatic from Eloquent models

```php
use Oriceon\N8nBridge\Concerns\TriggersN8nOnEvents;

class Invoice extends Model
{
    use TriggersN8nOnEvents;

    protected array $n8nTriggers = [
        'created' => 'invoice-created',
        'updated' => [
            'workflow'  => 'invoice-status-changed',
            'only_when' => ['status', 'paid_at'],
        ],
    ];
}
```

### Webhook mode (test vs production URL)

Each workflow has a `webhook_mode` that controls which n8n URL is called:

```php
use Oriceon\N8nBridge\Enums\WebhookMode;

N8nWorkflow::find($id)->update([
    'webhook_mode' => WebhookMode::Auto,        // default — /webhook-test in dev, /webhook in prod
    // 'webhook_mode' => WebhookMode::Production, // always /webhook
    // 'webhook_mode' => WebhookMode::Test,        // always /webhook-test
]);
```

With `Auto`, the URL is selected based on `APP_ENV`: **production** → `/webhook`, anything else → `/webhook-test`.

See [docs/outbound.md](docs/outbound.md) for full details.

### Outbound authentication

Protect your n8n webhooks with a per-workflow secret (AES-256 encrypted at rest):

```php
use Oriceon\N8nBridge\Auth\WebhookAuthService;
use Oriceon\N8nBridge\Enums\WebhookAuthType;

N8nWorkflow::where('name', 'order-shipped')->update([
    'auth_type' => WebhookAuthType::HmacSha256, // or HeaderToken / Bearer
    'auth_key'  => WebhookAuthService::generateKey(),
]);
```

Auth headers are added automatically by both the outbound dispatcher and the queue worker.

### Outbound rate limiting

Prevent flooding n8n with too many requests per minute:

```env
N8N_BRIDGE_OUTBOUND_RATE_LIMIT=30   # global: 30 req/min (0 = unlimited)
```

Per-workflow override:

```php
N8nWorkflow::where('name', 'bulk-sync')
    ->update(['rate_limit' => 10]);  // 10 req/min for this workflow
```

When the limit is exceeded: async triggers are re-dispatched after the window resets; sync triggers fail immediately; queue worker releases the job back to pending with a delay.

See [docs/outbound.md](docs/outbound.md) for auth types, rate limiting, n8n credential setup, and the HMAC verification Code node.

---

## 🔧 Tool System — n8n calls Laravel

Define typed Laravel endpoints that n8n nodes call as an internal API.

```bash
php artisan make:n8n-tool GetInvoiceTool
```

```php
final class GetInvoiceTool extends N8nToolHandler
{
    public function get(N8nToolRequest $request): N8nToolResponse
    {
        return N8nToolResponse::item(
            Invoice::findOrFail($request->required('id')),
            fn($i) => ['id' => $i->id, 'total' => $i->total, 'status' => $i->status]
        );
    }
}
```

```
GET  /n8n/tools/schema          → full OpenAPI 3 spec (import into n8n)
GET  /n8n/tools/{name}          → handler->get()
GET  /n8n/tools/{name}/{id}     → handler->getById()
POST /n8n/tools/{name}          → handler->post()
PATCH /n8n/tools/{name}/{id}    → handler->patch()
DELETE /n8n/tools/{name}/{id}   → handler->delete()
```

See [docs/tools.md](docs/tools.md) for filters, pagination, rate limiting, and schema definition.

---

## 📋 Queue System — async jobs with live progress

```php
use Oriceon\N8nBridge\Enums\QueueJobPriority;use Oriceon\N8nBridge\Queue\QueueDispatcher;

$job = QueueDispatcher::workflow('invoice-processing')
    ->payload(['invoice_id' => $invoice->id])
    ->priority(QueueJobPriority::High)
    ->maxAttempts(5)
    ->idempotent("invoice-{$invoice->id}")
    ->dispatch();
```

n8n sends checkpoint progress back:

```
POST /n8n/queue/progress/{jobUuid}
{ "node": "send_email", "status": "completed", "progress_percent": 75 }

POST /n8n/queue/progress/{jobUuid}
{ "node": "__done__", "status": "completed" }
```

Real-time updates via Laravel Echo:

```javascript
window.Echo.private(`n8n-job.${jobId}`)
    .listen('N8nQueueJobProgressUpdatedEvent', (e) => {
        updateTimeline(e.checkpoint);
        if (e.is_terminal) console.log('Done:', e.job_status);
    });
```

See [docs/queue.md](docs/queue.md) for full queue documentation — priorities, batches, DLQ, worker setup, Supervisor config, and estimated completion time.

---

## 🔄 Circuit Breaker

```
Closed ──(5 consecutive failures)──→ Open
  ↑                                       │
  │ (2× consecutive success) (60s cooldown)│
  └──── HalfOpen ←───────────────────────┘
```

See [docs/circuit-breaker.md](docs/circuit-breaker.md).

---

## 📊 Statistics

```php
$overview = N8nBridge::stats()->overview();
// ['total_deliveries' => 14823, 'success_rate' => 98.4, 'avg_duration_ms' => 142]

$chart = N8nBridge::stats()
    ->forWorkflow($workflow)
    ->lastDays(30)
    ->toChartData();
```

See [docs/statistics.md](docs/statistics.md).

---

## 🔔 Notifications

Alerts sent automatically on DLQ, circuit breaker open, and high error rate:

```env
N8N_BRIDGE_NOTIFY_CHANNELS=slack,discord,mail
N8N_BRIDGE_NOTIFY_SLACK_WEBHOOK=https://hooks.slack.com/services/xxx
N8N_BRIDGE_NOTIFY_MAIL_TO=ops@myapp.com
```

See [docs/notifications.md](docs/notifications.md).

---

## 🗄️ Database Tables

With the default `n8n` prefix:

| Table | Description |
|---|---|
| `n8n__credentials__lists` | Credential identities (one per n8n instance) |
| `n8n__api_keys__lists` | Rotatable API keys (many per credential) |
| `n8n__workflows__lists` | Workflows synced from n8n |
| `n8n__endpoints__lists` | Inbound endpoints |
| `n8n__deliveries__lists` | Full delivery log |
| `n8n__circuit_breakers__lists` | Per-workflow circuit breaker state |
| `n8n__stats__lists` | Daily aggregated statistics |
| `n8n__tools__lists` | Tool definitions |
| `n8n__event_subscriptions__lists` | Laravel event → workflow mappings |
| `n8n__queue__jobs` | DB queue jobs |
| `n8n__queue__batches` | Batch grouping |
| `n8n__queue__failures` | Per-attempt failure history |
| `n8n__queue__checkpoints` | Live progress nodes from n8n |

---

## 🛠️ Artisan Commands

```bash
# Webhook & endpoint management
php artisan n8n:credential:create "Production" --instance=default
php artisan n8n:credential:rotate {id} --grace=300
php artisan n8n:endpoint:create invoice-paid --handler="App\N8n\Handler"
php artisan n8n:endpoint:list
php artisan n8n:endpoint:rotate invoice-paid --grace=300

# Workflow sync
php artisan n8n:workflows:sync --instance=default
php artisan n8n:workflow:auth-setup "Workflow name" --type=header_token

# Testing
php artisan n8n:test-inbound invoice-paid --payload='{"invoice_id":42}'
php artisan n8n:test-inbound invoice-paid --dry-run

# DLQ
php artisan n8n:dlq:list
php artisan n8n:dlq:retry
php artisan n8n:dlq:retry {delivery-id}

# Queue
php artisan n8n:queue:work
php artisan n8n:queue:work --queue=critical --sleep=1
php artisan n8n:queue:status
php artisan n8n:queue:retry
php artisan n8n:queue:cancel {uuid}
php artisan n8n:queue:prune --days=30

# Stats & health
php artisan n8n:stats --last=30
php artisan n8n:health --instance=default

# Generators
php artisan make:n8n-tool GetInvoiceTool
```

---

## 🧪 Tests

```bash
composer test           # 846 tests (Unit + Feature + Architecture)
composer analyse        # PHPStan level 8
```

---

## 📚 Documentation

| Guide | Description |
|---|---|
| [docs/credentials.md](docs/credentials.md) | Credential keys, rotation, IP whitelist |
| [docs/inbound.md](docs/inbound.md) | Inbound handler, pipeline, HMAC, idempotency |
| [docs/outbound.md](docs/outbound.md) | Outbound triggers, Eloquent trait, event subscriptions, **outbound auth**, **webhook mode** |
| [docs/tools.md](docs/tools.md) | Tool handlers, routing, OpenAPI schema |
| [docs/queue.md](docs/queue.md) | DB queue, priorities, batches, DLQ, live progress |
| [docs/circuit-breaker.md](docs/circuit-breaker.md) | State machine, configuration |
| [docs/statistics.md](docs/statistics.md) | Stats aggregation, chart data |
| [docs/notifications.md](docs/notifications.md) | Alert channels, thresholds |
| [docs/security.md](docs/security.md) | Key hashing, HMAC, timing attacks |
| [docs/multitenancy.md](docs/multitenancy.md) | Multi-tenant setup |
| [docs/n8n-setup.md](docs/n8n-setup.md) | Configuring n8n credentials and nodes |
| [docs/testing.md](docs/testing.md) | Testing handlers, tools, and queue jobs |
| [docs/upgrade.md](docs/upgrade.md) | Upgrade guide |

---

## 📄 License

MIT — Copyright © 2026 [Valentin Ivașcu](https://github.com/oriceon)
