![Laravel N8N Bridge](images/banner.png)

# 📤 Outbound — Triggering n8n Workflows

← [Back to README](../README.md)

Three ways to trigger an n8n workflow from Laravel: directly via the facade, automatically from Eloquent model events, or via DB-subscribed Laravel events.

---

## 1. Via Facade (direct)

```php
use Oriceon\N8nBridge\Facades\N8nBridge;

// By workflow name (resolved from DB)
$delivery = N8nBridge::trigger('order-shipped', [
    'order_id'   => $order->id,
    'customer'   => $order->customer->email,
    'shipped_at' => now()->toIso8601String(),
]);

// Synchronous (waits for n8n response)
$delivery = N8nBridge::trigger('order-shipped', $payload, async: false);

// By model
$delivery = N8nBridge::trigger($workflow, $payload);
```

Returns an `N8nDelivery` record with `status`, `n8n_execution_id`, `http_status`, `response_raw`.

---

## 2. Eloquent model trait

Add `TriggersN8nOnEvents` to any model to automatically fire workflows on Eloquent events.

### Basic setup

```php
use Oriceon\N8nBridge\Concerns\TriggersN8nOnEvents;

class Invoice extends Model
{
    use TriggersN8nOnEvents;

    protected array $n8nTriggers = [
        'created' => 'invoice-created',
        'updated' => 'invoice-updated',
        'deleted' => 'invoice-deleted',
    ];
}
```

### Conditional triggers — only when specific fields change

```php
protected array $n8nTriggers = [
    'updated' => [
        'workflow'  => 'invoice-status-changed',
        'only_when' => ['status', 'paid_at'],   // fires only when these change
    ],
];
```

### Custom payload

```php
public function toN8nPayload(string $event): array
{
    return [
        'id'         => $this->id,
        'number'     => $this->invoice_number,
        'amount'     => $this->total,
        'currency'   => 'RON',
        'customer'   => [
            'id'    => $this->customer_id,
            'email' => $this->customer?->email,
        ],
        'event'      => $event,
        'changed'    => $event === 'updated' ? $this->getDirty() : null,
    ];
}
```

### Supported events

`created` | `updated` | `deleted` | `restored` | `saved`

---

## 3. Laravel Event subscriptions (DB-driven)

Subscribe a Laravel event class to a workflow. No code changes required — managed entirely via DB records.

```php
use Oriceon\N8nBridge\Models\N8nEventSubscription;

// Subscribe: when OrderShipped fires, trigger the workflow
N8nEventSubscription::create([
    'workflow_id' => $workflow->id,
    'event_class' => \App\Events\OrderShipped::class,
    'conditions'  => ['status' => 'completed'],  // optional filter
]);

// Now this automatically triggers the workflow:
event(new OrderShipped($order));
```

The package registers a wildcard `Event::listen('*', ...)` listener that checks the DB for matching subscriptions. Conditions are matched against the event's `toArray()` output.

---

## 4. Webhook mode — test vs production URL

n8n exposes two variants of every webhook:

| n8n URL | When active |
|---|---|
| `/webhook/{path}` | Workflow is **active** (production mode) |
| `/webhook-test/{path}` | Workflow is open in the n8n editor and **listening** (test mode) |

Control which URL the package calls using the `webhook_mode` column on each workflow:

| Mode | URL used | When to use |
|---|---|---|
| `Auto` (default) | env-based | `/webhook-test` in non-production, `/webhook` in production |
| `Production` | `/webhook` | Always hit the active workflow |
| `Test` | `/webhook-test` | Always hit the n8n editor test listener |

### Setting via code

```php
use Oriceon\N8nBridge\Enums\WebhookMode;
use Oriceon\N8nBridge\Models\N8nWorkflow;

// Default (auto-selects based on APP_ENV)
$workflow->update(['webhook_mode' => WebhookMode::Auto]);

// Always production
$workflow->update(['webhook_mode' => WebhookMode::Production]);

// Always test (useful for staging environments)
$workflow->update(['webhook_mode' => WebhookMode::Test]);
```

### Creating a workflow with a specific mode

```php
$workflow = N8nWorkflow::create([
    'name'         => 'order-shipped',
    'webhook_path' => 'order-shipped',     // just the path segment
    'direction'    => 'outbound',
    'n8n_instance' => 'default',
    'webhook_mode' => WebhookMode::Auto,   // or Production / Test
]);
```

### How the URL is resolved

```
webhook_mode = Auto, APP_ENV = local
→ https://n8n.example.com/webhook-test/order-shipped

webhook_mode = Auto, APP_ENV = production
→ https://n8n.example.com/webhook/order-shipped

webhook_mode = Production (any env)
→ https://n8n.example.com/webhook/order-shipped

webhook_mode = Test (any env)
→ https://n8n.example.com/webhook-test/order-shipped
```

The test base URL is auto-derived from `webhook_base_url` by replacing `/webhook` → `/webhook-test`. Set it explicitly if your n8n instance uses a different path:

```env
N8N_BRIDGE_N8N_DEFAULT_WEBHOOK_BASE_URL=https://n8n.myapp.com/webhook
# Optional — auto-derived when omitted
N8N_BRIDGE_N8N_DEFAULT_WEBHOOK_TEST_BASE_URL=https://n8n.myapp.com/webhook-test
```

---

## 5. Outbound authentication (Laravel → n8n)

By default, outbound requests are sent without authentication. Configure per-workflow using `auth_type` and `auth_key` (stored AES-256 encrypted).

### Auth types

| Type | Header(s) sent | n8n credential |
|---|---|---|
| `none` | — | No credential needed |
| `header_token` | `X-N8N-Workflow-Key: <token>` | Header Auth: Name=`X-N8N-Workflow-Key` |
| `bearer` | `Authorization: Bearer <token>` | Header Auth: Name=`Authorization`, Value=`Bearer <token>` |
| `hmac_sha256` | `X-N8N-Timestamp` + `X-N8N-Signature: sha256=<hmac>` | No credential — verified via Code node |

---

### Setup via code

```php
use Oriceon\N8nBridge\Auth\WebhookAuthService;
use Oriceon\N8nBridge\Enums\WebhookAuthType;
use Oriceon\N8nBridge\Models\N8nWorkflow;

// Generate a secure random key (64-char hex = 256 bits)
$key = WebhookAuthService::generateKey();

// Set on an existing workflow
$workflow = N8nWorkflow::where('name', 'order-shipped')->firstOrFail();
$workflow->auth_type = WebhookAuthType::HeaderToken;
$workflow->auth_key  = $key; // encrypted automatically by the model
$workflow->save();

echo "Store this key in your n8n credential: {$key}";
```

### Setup on workflow creation

```php
$workflow = N8nWorkflow::create([
    'name'               => 'order-shipped',
    'webhook_path'       => 'order-shipped',
    'direction'          => 'outbound',
    'n8n_instance'       => 'default',
    'auth_type'  => WebhookAuthType::Bearer,
    'auth_key'   => WebhookAuthService::generateKey(),
]);
```

### Factory shorthand (tests / seeders)

```php
use Oriceon\N8nBridge\Enums\WebhookAuthType;

$workflow = N8nWorkflow::factory()
    ->withAuth(WebhookAuthType::HmacSha256)  // auto-generates a 256-bit key
    ->create(['name' => 'my-workflow']);
```

---

### `header_token` — n8n setup

In n8n, add a **Header Auth** credential:

```
Settings → Credentials → New → Header Auth
  Name:  X-N8N-Workflow-Key
  Value: <paste your key>
```

Attach it to the **Webhook** node that receives your requests.

---

### `bearer` — n8n setup

```
Settings → Credentials → New → Header Auth
  Name:  Authorization
  Value: Bearer <paste your key>
```

---

### `hmac_sha256` — replay-protected signing

The dispatcher signs every request with:

```
message   = "{unix_timestamp}.{sha256(json_body)}"
signature = HMAC-SHA256(message, auth_key)
```

Headers sent:
- `X-N8N-Timestamp: <unix_timestamp>`
- `X-N8N-Signature: sha256=<hex_signature>`

Because the timestamp is part of the signed message, replaying a captured request (even with the right signature) will fail if you reject timestamps older than ~30 seconds.

**Verification in n8n (Code node, JavaScript):**

```javascript
const crypto = require('crypto');

const ts        = $input.params.header['x-n8n-timestamp'];
const signature = $input.params.header['x-n8n-signature'];
const body      = JSON.stringify($input.item.json);
const secret    = $env.WORKFLOW_HMAC_SECRET;   // store key as n8n env variable

// Reject stale requests (> 30 seconds old)
if (Math.abs(Date.now() / 1000 - Number(ts)) > 30) {
    throw new Error('Request timestamp is too old');
}

// Recompute and compare
const bodyHash = crypto.createHash('sha256').update(body).digest('hex');
const message  = `${ts}.${bodyHash}`;
const expected = 'sha256=' + crypto.createHmac('sha256', secret).update(message).digest('hex');

if (signature !== expected) {
    throw new Error('Invalid HMAC signature');
}

// Signature is valid — pass the item through
return [$input.item];
```

> **Store the key as an n8n environment variable** (`WORKFLOW_HMAC_SECRET`) rather than hardcoding it in the Code node. In n8n Cloud go to *Settings → Variables*; on self-hosted set it in the `.env` file.

---

### How encryption works

`auth_key` is stored with Laravel's `encrypted` cast (AES-256-CBC, tied to your `APP_KEY`). The plaintext key is never saved to the database. On each outbound request the model decrypts it on-the-fly, uses it to build the auth header, and discards it.

> If you rotate `APP_KEY`, re-encrypt all outbound keys: read the current plaintext, update the model, and let the cast re-encrypt with the new key.

---

### Checking auth status programmatically

```php
$workflow = N8nWorkflow::where('name', 'order-shipped')->first();

$workflow->auth_type;   // WebhookAuthType::HeaderToken
$workflow->auth_key;    // plaintext (decrypted at read time)
$workflow->hasWebhookAuth();    // true (type != none AND key is set)
```

---

## 6. Outbound rate limiting (flood protection)

Prevent Laravel from flooding n8n with too many requests per minute.

### Global limit (applies to all workflows)

```env
# Max outbound requests per minute to n8n. 0 = unlimited (default).
N8N_BRIDGE_OUTBOUND_RATE_LIMIT=30
```

Or in `config/n8n-bridge.php`:

```php
'outbound' => [
    'rate_limit' => 30,   // 30 req/min global default
    // ...
],
```

### Per-workflow override

Set `rate_limit` directly on the workflow record. `null` falls back to the global config; `0` means unlimited regardless of the global setting.

```php
// Limit a specific workflow to 10 req/min
N8nWorkflow::where('name', 'bulk-sync')
    ->update(['rate_limit' => 10]);

// Disable limiting for a workflow (even if global limit is set)
N8nWorkflow::where('name', 'order-critical')
    ->update(['rate_limit' => 0]);

// Reset to global config
N8nWorkflow::where('name', 'order-shipped')
    ->update(['rate_limit' => null]);
```

### Programmatic access

```php
$workflow->effectiveRateLimit();  // resolved value (int), 0 = unlimited
```

### Behaviour when limit is exceeded

| Context | What happens |
|---|---|
| **Async trigger** (`async: true`) | Job is re-dispatched with a delay equal to the remaining window |
| **Sync trigger** (`async: false`) | Delivery immediately marked `failed` with message `"Rate limited — retry in Ns"` |
| **Queue worker** | Job is released back to `pending` with `available_at = now() + wait_seconds` — no failure recorded, attempt counter unchanged |

The rate limiter uses Laravel's `RateLimiter` facade with a per-workflow key (`n8n_outbound_rl:{workflow_id}`) and a 60-second decay window, so the limit is **requests per minute**.

---

## 7. N8nDelivery — the outbound log

Every trigger creates a delivery record:

```php
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Enums\DeliveryDirection;

$delivery = N8nDelivery::query()
    ->outbound()
    ->forWorkflow($workflow->id)
    ->latest()
    ->first();

$delivery->status;           // DeliveryStatus enum
$delivery->http_status;      // 200, 500, etc.
$delivery->n8n_execution_id; // returned by n8n
$delivery->response_raw;     // full n8n response
$delivery->duration_ms;      // round-trip time
$delivery->error_message;    // set on failure
$delivery->attempts;         // retry count
```

---

## 8. Events fired

| Event                       | When |
|-----------------------------|---|
| `N8nWorkflowTriggeredEvent` | Immediately after a successful outbound trigger |
| `N8nDeliveryDeadEvent`      | After all retry attempts are exhausted |

```php
use Oriceon\N8nBridge\Events\N8nWorkflowTriggeredEvent;

Event::listen(N8nWorkflowTriggeredEvent::class, function ($event) {
    Log::info('Triggered: ' . $event->workflow->name);
});
```

---

## 9. Workflow model

```php
use Oriceon\N8nBridge\Auth\WebhookAuthService;
use Oriceon\N8nBridge\Enums\WebhookAuthType;
use Oriceon\N8nBridge\Enums\WebhookMode;
use Oriceon\N8nBridge\Models\N8nWorkflow;

// Create manually (no auth)
$workflow = N8nWorkflow::create([
    'name'         => 'invoice-paid',
    'webhook_path' => 'invoice-paid',    // path segment only — /webhook/ is prepended automatically
    'direction'    => 'outbound',
    'http_method'  => 'POST',
    'is_active'    => true,
    'n8n_instance' => 'default',
    'webhook_mode' => WebhookMode::Auto, // default
]);

// Create with outbound auth
$workflow = N8nWorkflow::create([
    'name'         => 'invoice-paid',
    'webhook_path' => 'invoice-paid',
    'direction'    => 'outbound',
    'n8n_instance' => 'default',
    'webhook_mode' => WebhookMode::Production,
    'auth_type'    => WebhookAuthType::HmacSha256,
    'auth_key'     => WebhookAuthService::generateKey(),
]);

// Sync from n8n API
N8nBridge::syncWorkflow($n8nWorkflowId);

// Or via command
php artisan n8n:workflows:sync --instance=default
```

### Workflow scopes

```php
N8nWorkflow::active()->get();
N8nWorkflow::forInstance('staging')->get();
N8nWorkflow::withTag('billing')->get();
N8nWorkflow::synced()->get();  // has n8n_id
```
