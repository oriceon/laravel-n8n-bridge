![Laravel N8N Bridge](images/banner.png)

# 📥 Inbound Webhooks

← [Back to README](../README.md)

n8n can call your Laravel application at any point during a workflow. This package exposes a secured HTTP endpoint that receives, authenticates, queues, and processes those payloads.

---

## How it works

```
n8n HTTP Request node
        │
        ▼  POST /n8n/in/{slug}
┌───────────────────────────────────┐
│         Security Pipeline         │
│  1. RateLimiter   (per slug)      │
│  2. ApiKeyVerifier                │
│  3. IpWhitelist   (optional)      │
│  4. HmacVerifier  (optional)      │
│  5. IdempotencyCheck              │
│  6. PayloadStore  → N8nDelivery   │
└───────────────────────────────────┘
        │
        ▼  ACK 202 immediately
        │
        ▼  Dispatch ProcessN8nInboundJob to queue
        │
        ▼  Validate rules() → run handle(N8nPayload)
```

The endpoint always returns `202 Accepted` immediately — your handler runs asynchronously in a queue worker.

---

## 1. Create an endpoint

```bash
php artisan n8n:endpoint:create invoice-paid \
  --handler="App\N8n\InvoicePaidHandler" \
  --queue=high \
  --rate-limit=60 \
  --max-attempts=3
```

Output:
```
✅ Endpoint created successfully!
🌐 URL: https://myapp.com/n8n/in/invoice-paid
🔑 API Key: n8br_sk_a3f9b2c1d4e5f6a7b8c9d0
```

The key is shown **once only**. Store it in your n8n credentials.

---

## 2. Write a handler

```php
<?php

namespace App\N8n;

use Oriceon\N8nBridge\DTOs\N8nPayload;
use Oriceon\N8nBridge\Inbound\N8nInboundHandler;

final class InvoicePaidHandler extends N8nInboundHandler
{
    public function handle(N8nPayload $payload): void
    {
        $invoiceId = $payload->required('invoice_id');   // throws if missing
        $amount    = $payload->getFloat('amount', 0.0);
        $paidAt    = $payload->getCarbon('paid_at');     // Carbon instance
        $meta      = $payload->getArray('metadata', []);

        Invoice::findOrFail($invoiceId)->update([
            'status'  => 'paid',
            'amount'  => $amount,
            'paid_at' => $paidAt,
            'meta'    => $meta,
        ]);

        event(new InvoicePaid(Invoice::find($invoiceId)));
    }

    /**
     * Validate the payload before handle() is called.
     * If validation fails, the delivery is marked as failed (no retry by default).
     */
    public function rules(): array
    {
        return [
            'invoice_id' => 'required|integer|min:1',
            'amount'     => 'required|numeric|min:0',
            'paid_at'    => 'nullable|date',
        ];
    }

    public function messages(): array
    {
        return [
            'invoice_id.required' => 'invoice_id is required.',
        ];
    }
}
```

### N8nPayload methods

| Method | Description |
|---|---|
| `->required('key')` | Get value, throw `MissingPayloadKeyException` if absent |
| `->getString('key', 'default')` | Get as string |
| `->getInt('key', 0)` | Get as int |
| `->getFloat('key', 0.0)` | Get as float |
| `->getBool('key', false)` | Get as bool |
| `->getArray('key', [])` | Get as array |
| `->getCarbon('key')` | Parse to `Carbon` instance (nullable) |
| `->has('key')` | Check if key exists |
| `->all()` | Get full payload as array |
| `->mapTo(MyDTO::class)` | Hydrate a DTO from the payload |

Dot notation is supported: `$payload->getString('customer.email')`.

---

## 3. Configure in n8n

Add an **HTTP Request** node in your n8n workflow:

| Field | Value |
|---|---|
| Method | `POST` |
| URL | `https://myapp.com/n8n/in/invoice-paid` |
| Header | `X-N8N-Key: n8br_sk_...` |
| Header | `X-N8N-Execution-Id: {{ $execution.id }}` (idempotency) |
| Body | JSON with your payload |

---

## 4. Security pipeline in detail

### API Key Verification

The key is sent as `X-N8N-Key` header. It is stored as a SHA-256 hash in the DB. Verification uses `hash_equals()` (constant-time) to prevent timing attacks.

Keys have a **prefix** (`n8br_sk_`) for visual identification without exposing the secret.

### Rate Limiting

Per-slug, backed by Laravel's `RateLimiter`. Default: 60 requests/minute. Set `--rate-limit=30` when creating.

### IP Whitelist

Optional. Set `allowed_ips` on the endpoint:

```php
use Oriceon\N8nBridge\Models\N8nEndpoint;

N8nEndpoint::where('slug', 'invoice-paid')->update([
    'allowed_ips' => ['203.0.113.10', '203.0.113.11'],
]);
```

### HMAC Signature Verification

Optional. When enabled, every request must include an `X-N8N-Signature` header with `sha256=<hex>`. The signature covers the raw request body (and optionally a timestamp for replay protection).

Enable it:

```php
N8nEndpoint::where('slug', 'invoice-paid')->update([
    'verify_hmac'  => true,
    'hmac_secret'  => 'your-shared-secret',  // stored encrypted at rest
]);
```

**Recommended — with timestamp (replay protection):**

In n8n, compute the signature with a **Code** node before the HTTP Request. Include the Unix timestamp so replayed requests are rejected:

```javascript
const crypto    = require('crypto');
const timestamp = String(Math.floor(Date.now() / 1000));
const body      = JSON.stringify($input.item.json);
const bodyHash  = crypto.createHash('sha256').update(body).digest('hex');
const message   = `${timestamp}.${bodyHash}`;
const sig       = 'sha256=' + crypto
    .createHmac('sha256', $env.HMAC_SECRET)
    .update(message)
    .digest('hex');

return [{ json: { ...($input.item.json), _signature: sig, _timestamp: timestamp } }];
```

Then set two headers on the HTTP Request node:
- `X-N8N-Signature: {{ $json._signature }}`
- `X-N8N-Timestamp: {{ $json._timestamp }}`

The package rejects any request with a timestamp older than **5 minutes**.

**Legacy — body-only (no timestamp):**

If you do not send `X-N8N-Timestamp`, the signature is verified against the raw body only. No replay protection:

```javascript
const crypto = require('crypto');
const body   = JSON.stringify($input.item.json);
const sig    = 'sha256=' + crypto
    .createHmac('sha256', $env.HMAC_SECRET)
    .update(body)
    .digest('hex');
return [{ json: { ...($input.item.json), _signature: sig } }];
```

Then set header `X-N8N-Signature: {{ $json._signature }}`.

### Idempotency

Send the `X-N8N-Execution-Id` header. If a delivery with that execution ID already exists and was processed, the endpoint returns `200` immediately without re-processing. Prevents duplicate processing on n8n retries.

---

## 5. Rotate API keys (zero downtime)

```bash
php artisan n8n:endpoint:rotate invoice-paid --grace=300
```

The old key remains valid for 300 seconds while you update n8n. After the grace period, it is revoked automatically.

---

## 6. Test without n8n

```bash
# Full execution
php artisan n8n:test-inbound invoice-paid \
  --payload='{"invoice_id":42,"amount":150.00}'

# Validate only (no handler execution)
php artisan n8n:test-inbound invoice-paid \
  --payload='{"invoice_id":42}' \
  --dry-run
```

---

## 7. Delivery log

Every inbound request creates an `N8nDelivery` record:

```php
use Oriceon\N8nBridge\Models\N8nDelivery;

// All inbound deliveries for a workflow
$deliveries = N8nDelivery::query()
    ->inbound()
    ->forWorkflow($workflow->id)
    ->with('endpoint')
    ->latest()
    ->paginate(20);

// Failed deliveries
$failed = N8nDelivery::query()
    ->failed()
    ->where('created_at', '>=', now()->subDay())
    ->get();
```

---

## 8. Endpoint configuration reference

| Column | Type | Default | Description |
|---|---|---|---|
| `slug` | string | — | URL segment, unique |
| `handler_class` | string | — | FQCN of your handler |
| `queue` | string | `default` | Laravel queue to dispatch on |
| `auth_type` | enum | `api_key` | `api_key`, `bearer`, `hmac`, `none` |
| `allowed_ips` | JSON | null | Whitelist, null = allow all |
| `verify_hmac` | bool | false | Enable HMAC verification |
| `hmac_secret` | string | null | Shared secret for HMAC |
| `rate_limit` | int | 60 | Requests per minute |
| `store_payload` | bool | true | Persist raw payload to DB |
| `retry_strategy` | enum | `exponential` | `exponential`, `linear`, `fixed`, `fibonacci` |
| `max_attempts` | int | 3 | Max retry attempts |
| `is_active` | bool | true | Disable without deleting |
| `expires_at` | datetime | null | Auto-expire the endpoint |
