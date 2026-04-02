![Laravel N8N Bridge](images/banner.png)

# ⚙️ n8n Setup Guide

← [Back to README](../README.md)

Practical instructions for configuring the n8n side of the integration.

---

## 1. Store credentials in n8n

Go to **Personal → Credentials → Create credential → Header Auth**.

Create **one credential** for your entire Laravel app — reuse it on every HTTP Request node:

| Field | Value |
|---|---|
| Name | `Laravel n8n Key` |
| Header Name | `X-N8N-Key` |
| Header Value | `n8br_sk_...` (from `php artisan n8n:credential:create`) |

One key authenticates all routes: `/n8n/in/*`, `/n8n/tools/*`, and `/n8n/queue/progress/*`.

See [docs/credentials.md](credentials.md) for setup details.

---

## 2. Inbound → Laravel pattern

Use an **HTTP Request** node to call your Laravel endpoint:

```
Node: HTTP Request
  Method:      POST
  URL:         https://myapp.com/n8n/in/invoice-paid
  Auth:        Header Auth → "Laravel Invoice Paid"
  Body:        JSON
  Body Params:
    invoice_id   = {{ $json.invoice_id }}
    amount       = {{ $json.amount }}
    paid_at      = {{ $now.toISO() }}
```

### Add idempotency key

Add a second header:

```
X-N8N-Execution-Id:  {{ $execution.id }}
```

This prevents duplicate processing if n8n retries the node.

---

## 3. Include job_id for progress tracking

When your Laravel app dispatches a queue job, it needs to pass the `job_id` to n8n so n8n can report checkpoints back.

**Pattern A** — pass job_id in the outbound webhook payload:

In `toN8nPayload()` on your model (or when using `QueueDispatcher`), include:

```php
public function toN8nPayload(string $event): array
{
    // job_id will be null here — the worker fills it in
    // OR dispatch manually and pass it:
    $job = QueueDispatcher::workflow('invoice-processing')
        ->payload(['invoice_id' => $this->id])
        ->dispatch();

    return [
        'invoice_id' => $this->id,
        'job_id'     => $job->id,
    ];
}
```

**Pattern B** — n8n workflow sends job_id back in its first HTTP node:

The `QueueWorker` always sends `_n8n_job_id` in the payload. Access it in n8n as `{{ $json._n8n_job_id }}`.

---

## 4. Progress checkpoint nodes

After each significant node in your workflow, add an **HTTP Request** node:

```
Node: HTTP Request — "Report Progress: Fetch Invoice"
  Method: POST
  URL:    https://myapp.com/n8n/queue/progress/{{ $('Webhook Trigger').item.json._n8n_job_id }}
  Auth:   Header Auth → "Laravel Progress Key"
  Body:
    node:             fetch_invoice
    node_label:       Fetch Invoice Data
    status:           completed
    message:          Invoice {{ $json.invoice_number }} loaded
    data:             { "invoice_number": "{{ $json.invoice_number }}" }
    progress_percent: 25
```

### Final nodes

At the end of your workflow, always send one of:

**Success:**
```json
{
  "node":    "__done__",
  "status":  "completed",
  "message": "{{ $execution.id }}"
}
```

**Failure (in an Error Trigger or catch branch):**
```json
{
  "node":          "__failed__",
  "status":        "failed",
  "error_message": "{{ $json.error.message }}"
}
```

---

## 5. Call Laravel tools from n8n

```
Node: HTTP Request — "Get Contact"
  Method: POST
  URL:    https://myapp.com/n8n/tools/get-contact
  Body:
    email:  {{ $json.customer_email }}
```

Response is available as `$json.data.name`, `$json.data.company`, etc.

Import the full tool schema at `GET https://myapp.com/n8n/tools/schema` to get all available tools with their parameter documentation.

---

## 6. Workflow template: full bidirectional example

```
[Webhook Trigger]
    ↓
[Set: extract invoice_id, job_id from payload]
    ↓
[HTTP: Report "running" checkpoint]
    ↓
[HTTP: Fetch invoice from SmartBill API]
    ↓
[HTTP: Report "fetch complete" checkpoint, progress=33]
    ↓
[HTTP: Enrich with contact data from Laravel tool]
    ↓
[HTTP: Report "enrich complete" checkpoint, progress=66]
    ↓
[HTTP: Send email via SendGrid]
    ↓
[HTTP: Report "__done__", progress=100]
         ↓ (on error)
[Error Trigger]
    ↓
[HTTP: Report "__failed__" checkpoint]
```

---

## 7. Environment variables in n8n

Store your Laravel URLs and keys in n8n's **Environment Variables** (Settings → Environment Variables):

| Key | Value |
|---|---|
| `APP_URL` | `https://myapp.com` |
| `PROGRESS_API_KEY` | `your-progress-key` |
| `HMAC_SECRET` | `your-hmac-secret` |

Reference them in nodes as `{{ $env.APP_URL }}`.

---

## 8. Webhook mode — test vs production URLs

n8n exposes two webhook URL variants per workflow:

| URL | Required state |
|---|---|
| `/webhook/{path}` | Workflow must be **active** (deployed) |
| `/webhook-test/{path}` | Workflow must be **open in the editor** and listening |

The package selects the URL automatically based on each workflow's `webhook_mode`:

- `Auto` (default) — uses `/webhook-test` when `APP_ENV != production`, otherwise `/webhook`
- `Production` — always uses `/webhook` regardless of environment
- `Test` — always uses `/webhook-test`

> During n8n workflow development, keep `webhook_mode = Auto` so local/staging calls hit the test listener while production calls hit the active workflow without any config changes.

Set per workflow:

```php
use Oriceon\N8nBridge\Enums\WebhookMode;

$workflow->update(['webhook_mode' => WebhookMode::Test]); // always use /webhook-test
```

Or configure the explicit test URL in `.env` (auto-derived when omitted):

```env
N8N_BRIDGE_N8N_DEFAULT_WEBHOOK_BASE_URL=https://n8n.myapp.com/webhook
N8N_BRIDGE_N8N_DEFAULT_WEBHOOK_TEST_BASE_URL=https://n8n.myapp.com/webhook-test
```

---

## 9. Debugging tips

- Use `n8n:test-inbound` to test handlers without needing a running n8n instance.
- Check `n8n__deliveries` for the raw payload and HTTP status of every inbound/outbound call.
- Check `n8n__queue__failures` for full stack traces on failed queue jobs.
- Use `n8n:queue:status --watch` for a live terminal view of the queue.
- The n8n **Execution History** shows every run — cross-reference `executionId` with the `n8n_execution_id` column in deliveries.
