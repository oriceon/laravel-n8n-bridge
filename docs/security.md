![Laravel N8N Bridge](images/banner.png)

# 🔑 Security

← [Back to README](../README.md)

---

## API Keys — Webhook-based Authentication

Authentication uses a single credential key per n8n instance. One key authenticates all routes: `/n8n/in`, `/n8n/tools`, and `/n8n/queue/progress`.

See [docs/credentials.md](credentials.md) for the full setup guide.

### Storage

Keys are **never stored in plaintext**. Only the SHA-256 hash is persisted.

```
Plaintext:  n8br_sk_a3f9b2c1d4e5f6a7b8c9d0e1f2a3b4c5
Stored:     sha256(n8br_sk_a3f9b2c1d4e5f6a7b8c9d0e1f2a3b4c5)
```

The `n8br_sk_` prefix identifies credential keys in logs without exposing the secret.

### Verification

All comparisons use `hash_equals()` — constant-time, prevents timing attacks. Results are cached 60s per key via Laravel Cache.

### Key rotation (zero downtime)

```bash
php artisan n8n:credential:rotate {id} --grace=300
```

Old key stays in `grace` status for 300 seconds — update n8n within that window. After grace, old key is automatically revoked.

### Key lifecycle

```
active → (rotate) → grace → (grace expires) → revoked
active → (manual revoke)  → revoked
```

---

## HMAC Signature Verification

For endpoints that require payload integrity guarantees, enable HMAC-SHA256 verification. This is the same mechanism used by Stripe and GitHub webhooks. The `hmac_secret` is stored AES-256 encrypted at rest.

### Setup

```php
N8nEndpoint::where('slug', 'billing-events')->update([
    'verify_hmac' => true,
    'hmac_secret' => env('BILLING_HMAC_SECRET'),
]);
```

### How the signature is computed — with timestamp (recommended)

Including a Unix timestamp in the signed message prevents replay attacks: a captured request cannot be re-submitted after the 5-minute age limit.

```
body_hash = SHA-256(raw_body)
message   = "{unix_timestamp}.{body_hash}"
signature = "sha256=" + HMAC-SHA256(message, hmac_secret)
```

Headers sent by n8n:
- `X-N8N-Timestamp: <unix_timestamp>`
- `X-N8N-Signature: sha256=<hex_signature>`

The package rejects any request whose timestamp is **more than 5 minutes old**.

### In n8n (Code node — with timestamp)

```javascript
const crypto    = require('crypto');
const timestamp = String(Math.floor(Date.now() / 1000));
const payload   = JSON.stringify($input.item.json);
const bodyHash  = crypto.createHash('sha256').update(payload).digest('hex');
const message   = `${timestamp}.${bodyHash}`;
const sig       = 'sha256=' + crypto
    .createHmac('sha256', $env.HMAC_SECRET)
    .update(message)
    .digest('hex');

return [{ json: { ...($input.item.json), _sig: sig, _ts: timestamp } }];
```

Then in the HTTP Request node add:
- `X-N8N-Signature: {{ $json._sig }}`
- `X-N8N-Timestamp: {{ $json._ts }}`

### Legacy — body-only (no replay protection)

If `X-N8N-Timestamp` is absent the package falls back to verifying against the raw body only — backwards-compatible with existing integrations that do not send the timestamp header:

```
signature = "sha256=" + HMAC-SHA256(raw_body, hmac_secret)
```

```javascript
const crypto  = require('crypto');
const payload = JSON.stringify($input.item.json);
const sig     = 'sha256=' + crypto
    .createHmac('sha256', $env.HMAC_SECRET)
    .update(payload)
    .digest('hex');

return [{ json: { ...($input.item.json), _sig: sig } }];
```

Then add header `X-N8N-Signature: {{ $json._sig }}`.

> New integrations should always include `X-N8N-Timestamp` for replay protection.

---

## IP Whitelisting

Restrict an endpoint to specific source IPs:

```php
N8nEndpoint::where('slug', 'internal-sync')->update([
    'allowed_ips' => [
        '203.0.113.10',         // single IP
        '203.0.113.0/24',       // CIDR range (if your implementation supports it)
    ],
]);
```

A request from an unlisted IP returns `403 Forbidden` before any key verification happens.

Get your n8n cloud IP range from the n8n documentation and add it here.

---

## Rate Limiting

Per-endpoint, backed by Laravel's built-in `RateLimiter`. Default: 60 requests per minute.

```bash
# Set at creation
php artisan n8n:endpoint:create my-endpoint --rate-limit=30

# Or update directly
N8nEndpoint::where('slug', 'my-endpoint')->update(['rate_limit' => 30]);
```

Exceeded requests return `429 Too Many Requests`.

---

## Progress endpoint authentication

The queue progress endpoint (`POST /n8n/queue/progress/{jobId}`) supports two authentication modes:

**Global key** (all jobs share one key — simpler):

```env
N8N_BRIDGE_QUEUE_PROGRESS_KEY=your-shared-secret
```

**Per-workflow key** (uses the same key as the workflow's inbound endpoint — more granular):

Leave `N8N_BRIDGE_QUEUE_PROGRESS_KEY` unset. The controller will look up the active API key for the job's associated workflow endpoint.

---

## Route middleware

All package routes use the `api` middleware group by default. Override in config:

```php
'inbound' => [
    'route_prefix' => 'n8n/in',
    'middleware'   => ['api', 'throttle:60,1'],
],
'tools' => [
    'route_prefix' => 'n8n/tools',
    'middleware'   => ['api'],
],
'queue' => [
    'progress_route_prefix' => 'n8n/queue/progress',
    'progress_middleware'   => ['api'],
],
```

---

## Secrets in `.env`

Never commit secrets to version control. Always use environment variables:

```env
# n8n API key
N8N_API_KEY=your-n8n-api-key

# HMAC secrets per endpoint (one per endpoint that uses HMAC)
INVOICE_HMAC_SECRET=abc...
BILLING_HMAC_SECRET=xyz...

# Progress endpoint key
N8N_BRIDGE_QUEUE_PROGRESS_KEY=prgr_...
```
