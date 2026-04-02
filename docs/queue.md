![Laravel N8N Bridge](images/banner.png)

# 📋 Queue System

← [Back to README](../README.md)

The `laravel-n8n-bridge` DB queue is a **database-driven dispatch layer** that sits between your Laravel application and n8n workflows. Instead of firing HTTP calls synchronously, you insert jobs into a DB table and dedicated workers process them with full priority control, failure handling, retry logic, and live progress tracking.

---

## Table of Contents

1. [Why a DB queue?](#why-a-db-queue)
2. [Architecture overview](#architecture-overview)
3. [Installation & migration](#installation--migration)
4. [Dispatching jobs](#dispatching-jobs)
5. [Priority levels](#priority-levels)
6. [Running workers](#running-workers)
7. [Supervisor setup](#supervisor-setup)
8. [Batches](#batches)
9. [Failure handling](#failure-handling)
10. [Dead Letter Queue (DLQ)](#dead-letter-queue-dlq)
11. [Live progress tracking](#live-progress-tracking)
12. [Estimated duration & progress bar](#estimated-duration--progress-bar)
13. [Queue statistics](#queue-statistics)
14. [Deleting done jobs](#deleting-done-jobs)
15. [Artisan commands reference](#artisan-commands-reference)
16. [Configuration reference](#configuration-reference)
17. [Events fired](#events-fired)

---

## Why a DB queue?

| Concern | Direct `N8nBridge::trigger()` | DB Queue |
|---|---|---|
| High volume (10k+ jobs) | ❌ Blocks / times out | ✅ Async, bulk insert |
| Priority control | ❌ None | ✅ 5 levels |
| Retry on failure | ❌ Manual | ✅ Automatic with backoff |
| Live progress tracking | ❌ Fire-and-forget | ✅ Checkpoint API |
| Estimated completion time | ❌ None | ✅ Rolling EMA per workflow |
| Dead Letter Queue | ❌ None | ✅ Full audit trail |
| Batch operations | ❌ None | ✅ Built-in grouping |
| Worker health monitoring | ❌ None | ✅ Lease + stuck recovery |

---

## Architecture overview

```
Your app
  │
  ▼  INSERT
n8n__queue__jobs  (status=pending, priority=75)
  │
  ▼  Worker: SELECT FOR UPDATE SKIP LOCKED
  │  (atomic claim — safe for multiple workers)
  │
  ▼  POST webhook  (+auth headers if auth_type ≠ none)
n8n workflow executes
  │
  ├── n8n sends checkpoints → POST /n8n/queue/progress/{jobId}
  │     stored in n8n__queue__checkpoints
  │     broadcast via Laravel Echo (Reverb/Pusher)
  │
  └── n8n sends __done__ → job marked Done
        observer fires:
          - deletes checkpoints (if configured)
          - updates workflow EMA duration
```

**DB tables created:**

| Table | Purpose |
|---|---|
| `n8n__queue__jobs` | Individual dispatch jobs — the core queue |
| `n8n__queue__batches` | Groups for bulk operations |
| `n8n__queue__failures` | Immutable per-attempt failure history |
| `n8n__queue__checkpoints` | Live progress nodes sent by n8n |

---

## Setup N8N Workflow

1. In your n8n workflow, start with adding an **Webhook**
2. Set desired HTTP method
3. Set Authenticaton type to **Header Auth**
4. Create a new credential with:
   - Name: `X-N8N-Workflow-Key`
   - Value: `your secret`
5. **IMPORTANT!** To be able to get back execution_id, set respond method to **Using 'Respond to Webhook' Node**
6. Then, right after the main webhook, add a new node **Respond to Webhook** flow with the following settings:
   - Respond With: `JSON`
   - Response Body: `{
  "execution_id": "{{ $execution.id }}",
  "workflow_id": "{{ $workflow.id }}",
  "workflow_name": "{{ $workflow.name }}",
  "message": "Workflow was started"
}`

## Installation

Add to `.env`:

```env
N8N_BRIDGE_QUEUE_DEFAULT=default
N8N_BRIDGE_QUEUE_PROGRESS_KEY=your-secret-key   # for the progress endpoint
N8N_BRIDGE_QUEUE_DELETE_CHECKPOINTS=true        # auto-delete on success
N8N_BRIDGE_QUEUE_DURATION_SAMPLES=50            # EMA window size
```

---

## Dispatching jobs

### Single job — fluent API

```php
use Oriceon\N8nBridge\Enums\QueueJobPriority;
use Oriceon\N8nBridge\Queue\QueueDispatcher;

// Minimal
QueueDispatcher::workflow('invoice-paid')
    ->payload(['invoice_id' => $invoice->id])
    ->dispatch();

// With full options
QueueDispatcher::workflow('invoice-paid')
    ->payload(['invoice_id' => $invoice->id, 'amount' => $invoice->total])
    ->context(['triggered_by' => 'InvoicePaidListener', 'user_id' => auth()->id()])
    ->priority(QueueJobPriority::High)
    ->delay(minutes: 5)
    ->maxAttempts(5)
    ->timeout(60)
    ->onQueue('high')
    ->idempotent("invoice-paid-{$invoice->id}")  // skip if already queued
    ->dispatch();
```

### Via the facade

```php
use Oriceon\N8nBridge\Facades\N8nBridge;

N8nBridge::queue()->dispatch(
    workflow: 'invoice-paid',
    payload:  ['invoice_id' => 42],
    priority: QueueJobPriority::High,
);
```

### Fluent priority shortcuts

```php
QueueDispatcher::workflow('order-alert')->payload($data)->critical()->dispatch();
QueueDispatcher::workflow('order-sync')->payload($data)->high()->dispatch();
QueueDispatcher::workflow('report-gen')->payload($data)->low()->dispatch();
QueueDispatcher::workflow('bulk-import')->payload($data)->bulk()->dispatch();
```

### Scheduled / delayed dispatch

```php
// Delay by duration
QueueDispatcher::workflow('follow-up-email')
    ->payload(['contact_id' => $id])
    ->delay(hours: 24)
    ->dispatch();

// Available at a specific time
QueueDispatcher::workflow('reminder')
    ->payload(['appointment_id' => $id])
    ->availableAt(Carbon::parse('2026-04-01 09:00:00'))
    ->dispatch();
```

### Idempotent dispatch

If the same key already has a pending/running job, `dispatch()` returns the existing job without creating a duplicate:

```php
$job = QueueDispatcher::workflow('invoice-paid')
    ->payload(['invoice_id' => 42])
    ->idempotent("invoice-paid-42")
    ->dispatch();

// $job->wasRecentlyCreated is false if it already existed
```

---

## Priority levels

| Priority | Value | Default max attempts | Default timeout | Use for |
|---|---|---|---|---|
| `Critical` | 100 | 10 | 30s | Billing, security events |
| `High` | 75 | 5 | 60s | User-facing actions |
| `Normal` | 50 | 3 | 120s | Standard operations |
| `Low` | 25 | 3 | 300s | Background processing |
| `Bulk` | 10 | 2 | 600s | Mass imports, nightly sync |

Workers pick jobs in `ORDER BY priority DESC, available_at ASC` — critical jobs always go first, and within the same priority, oldest jobs go first (FIFO).

---

## Running workers

```bash
# Default worker (processes all priority levels)
php artisan n8n:queue:work

# Dedicated workers per priority tier
php artisan n8n:queue:work --queue=critical
php artisan n8n:queue:work --queue=high,normal
php artisan n8n:queue:work --queue=bulk --sleep=5

# Run once (useful for testing)
php artisan n8n:queue:work --once

# Limit runtime
php artisan n8n:queue:work --max-jobs=500 --max-time=3600

# Recover stuck jobs after a crash, then exit
php artisan n8n:queue:work --recover
```

### Outbound authentication per workflow

The worker automatically sends authentication headers on every webhook call based on the workflow's `auth_type` setting. No extra configuration is needed — just set the auth type on the workflow once:

```php
use Oriceon\N8nBridge\Auth\WebhookAuthService;
use Oriceon\N8nBridge\Enums\WebhookAuthType;
use Oriceon\N8nBridge\Models\N8nWorkflow;

$workflow = N8nWorkflow::where('name', 'invoice-reminder')->firstOrFail();
$workflow->auth_type = WebhookAuthType::HeaderToken;
$workflow->auth_key  = WebhookAuthService::generateKey(); // encrypted automatically by the model
$workflow->save();
```

| Auth type | Header sent by worker | n8n setup |
|---|---|---|
| `none` (default) | None | Open webhook |
| `header_token` | `X-N8N-Workflow-Key: <token>` | Header Auth credential |
| `bearer` | `Authorization: Bearer <token>` | Header Auth credential |
| `hmac_sha256` | `X-N8N-Timestamp` + `X-N8N-Signature: sha256=<hmac>` | Code node verification |

See [docs/outbound.md §4](outbound.md#4-outbound-authentication-laravel--n8n) for full setup instructions, including the HMAC verification Code node for n8n.

---

### How claiming works

Workers use `SELECT FOR UPDATE SKIP LOCKED` — multiple workers can run concurrently on the same table without stepping on each other. Each worker holds a **lease** (default: `timeout + 30s`). If a worker crashes mid-job, the lease expires and the job is automatically recovered by the next worker that calls `--recover` or starts up.

---

## Supervisor setup

Recommended configuration for production — 3 workers watching different priority tiers:

```ini
[program:n8n-queue-critical]
command=php /var/www/artisan n8n:queue:work --queue=critical --sleep=1
autostart=true
autorestart=true
stopwaitsecs=30
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/n8n-queue-critical.log

[program:n8n-queue-normal]
command=php /var/www/artisan n8n:queue:work --queue=high,normal --sleep=1
autostart=true
autorestart=true
stopwaitsecs=60
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/n8n-queue-normal.log

[program:n8n-queue-bulk]
command=php /var/www/artisan n8n:queue:work --queue=bulk --sleep=5
autostart=true
autorestart=true
stopwaitsecs=120
numprocs=1
redirect_stderr=true
stdout_logfile=/var/log/n8n-queue-bulk.log
```

---

## Batches

Use batches when dispatching many jobs for the same operation (e.g. "send invoice reminders to 5,000 customers").

### From an array

```php
use Oriceon\N8nBridge\Facades\N8nBridge;

$payloads = $invoices->map(fn($i) => ['invoice_id' => $i->id, 'email' => $i->customer->email]);

$batch = N8nBridge::queue()->dispatchMany(
    workflow:  'invoice-reminder',
    payloads:  $payloads,
    priority:  QueueJobPriority::Bulk,
    batchName: 'Invoice reminders — March 2026',
);

echo "Batch ID: {$batch->id}";
echo "Total jobs: {$batch->total_jobs}";
```

### From an Eloquent query (memory-efficient)

```php
$batch = N8nBridge::queue()->dispatchFromQuery(
    workflow:  'invoice-reminder',
    query:     Invoice::overdue()->with('customer'),
    map:       fn($invoice) => [
        'invoice_id' => $invoice->id,
        'email'      => $invoice->customer->email,
        'days_overdue' => $invoice->days_overdue,
    ],
    batchName: 'Overdue reminders',
    chunkSize: 1000,
);
```

Uses `chunkById()` under the hood — handles millions of rows without memory issues.

### Monitoring batch progress

```php
$batch = N8nBridge::queue()->batch($batchId);

echo $batch->progressPercent();  // 73.4
echo $batch->successRate();      // 98.1
echo $batch->done_jobs;          // 3671
echo $batch->dead_jobs;          // 12
echo $batch->isPending();        // true

// Cancel all pending jobs in a batch
$batch->cancel();
```

---

## Failure handling

### Retry strategies per failure reason

| Failure reason | Retryable | Suggested delay |
|---|---|---|
| `connection_timeout` | ✅ | 15s |
| `http_5xx` | ✅ | 30s |
| `rate_limit` (429) | ✅ | 60s (scales with attempt count) |
| `circuit_breaker` | ✅ | 60s |
| `worker_timeout` | ✅ | 30s |
| `http_4xx` | ❌ | — (won't retry) |
| `workflow_not_found` | ❌ | — (won't retry) |
| `payload_too_large` | ❌ | — (won't retry) |
| `validation` | ❌ | — (won't retry) |

### Failure lifecycle

```
attempt 1 fails  →  status=failed, available_at=now()+30s  →  back to pending
attempt 2 fails  →  status=failed, available_at=now()+60s  →  back to pending
attempt 3 fails  →  status=dead  →  alert sent via notification channels
```

Each failure appends a row to `n8n__queue__failures` with the full error, HTTP status, stack trace, and a snapshot of the payload at the time of failure.

### Listening for failures in your app

```php
use Oriceon\N8nBridge\Events\N8nQueueJobFailedEvent;

Event::listen(N8nQueueJobFailedEvent::class, function (N8nQueueJobFailedEvent $event) {
    Log::warning('Queue job failed', [
        'job_id'   => $event->job->id,
        'workflow' => $event->job->workflow->name,
        'reason'   => $event->failure->reason->label(),
        'attempt'  => $event->failure->attempt_number,
    ]);
});
```

---

## Dead Letter Queue (DLQ)

Jobs that exhaust all retry attempts move to `status=dead`. Their failure history is fully preserved in `n8n__queue__failures`.

```bash
# List dead jobs
php artisan n8n:queue:retry --dry-run

# Retry all dead jobs
php artisan n8n:queue:retry

# Retry dead jobs for one workflow
php artisan n8n:queue:retry --workflow=invoice-paid

# Retry only jobs that failed due to server errors
php artisan n8n:queue:retry --reason=http_5xx

# Reset attempt counter (gives full retry budget)
php artisan n8n:queue:retry --reset-attempts

# Boost priority when retrying
php artisan n8n:queue:retry --workflow=invoice-paid --priority=high

# Retry via code
$job = N8nBridge::queue()->job($jobId);
// Then call artisan or update directly:
$job->update([
    'status'       => QueueJobStatus::Pending->value,
    'max_attempts' => $job->attempts + 2,
    'available_at' => null,
]);
```

---

## Live progress tracking

n8n sends checkpoint updates to your Laravel app during workflow execution.

### 1. Configure n8n

Add an **HTTP Request** node after each significant step in your workflow:

```
URL:    POST {{ $env.APP_URL }}/n8n/queue/progress/{{ $('Webhook').item.json.job_id }}
Method: POST
Headers:
  X-N8N-Key: your-progress-api-key
Body (JSON):
  {
    "node":             "send_invoice_email",
    "node_label":       "Send Invoice Email",
    "status":           "completed",
    "message":          "Email sent to {{ $json.customer_email }}",
    "data":             { "message_id": "{{ $json.message_id }}" },
    "progress_percent": 75
  }
```

**Status values:** `running` | `completed` | `failed` | `skipped` | `waiting`

**Special node names:**
- `__done__` — marks the job as `Done` in the DB
- `__failed__` — marks the job as `Failed` in the DB

### 2. Pass job_id to n8n

When dispatching, include the job ID in the payload so n8n can send it back:

```php
$job = QueueDispatcher::workflow('invoice-processing')
    ->payload(['invoice_id' => $invoice->id])
    ->dispatch();

// The webhook triggers n8n with job_id in payload
// n8n reads it as {{ $json.job_id }} (or however you pass it)
```

The `N8nOutboundDispatcher` automatically includes `_n8n_job_id` in the payload when dispatching from the queue worker. You can also do it manually:

```php
QueueDispatcher::workflow('invoice-processing')
    ->payload([
        'invoice_id' => $invoice->id,
        'job_id'     => null,  // will be filled after dispatch
    ])
    ->dispatch();
```

### 3. Poll the timeline (REST)

```http
GET /n8n/queue/progress/{jobId}
```

Response:

```json
{
  "job": {
    "id": "uuid",
    "workflow": "invoice-processing",
    "status": "running",
    "status_label": "Running",
    "priority": "High",
    "attempts": 1,
    "n8n_execution_id": 123,
    "started_at": "2026-03-22T10:00:00Z",
    "finished_at": null,
    "duration_ms": null
  },
  "timeline": [
    {
      "id": "uuid",
      "node": "fetch_invoice",
      "label": "Fetch Invoice Data",
      "status": "completed",
      "color": "green",
      "icon": "check-circle",
      "message": "Invoice #1042 loaded",
      "data": { "invoice_number": "INV-1042" },
      "error": null,
      "progress": 25,
      "sequence": 1,
      "at": "2026-03-22T10:00:01Z"
    },
    {
      "node": "send_email",
      "label": "Send Confirmation Email",
      "status": "running",
      "sequence": 2,
      ...
    }
  ],
  "total_steps": 4,
  "completed_steps": 1,
  "has_failures": false,
  "progress_percent": 25
}
```

### 4. Real-time via Laravel Echo (broadcasting)

The `N8nQueueJobProgressUpdatedEvent` event implements `ShouldBroadcast` and fires on every checkpoint. Subscribe using Laravel Echo:

```javascript
// Vanilla JS / Alpine.js
window.Echo
    .private(`n8n-job.${jobId}`)
    .listen('N8nQueueJobProgressUpdatedEvent', (e) => {
        console.log(e.checkpoint);
        // { node, label, status, color, icon, message, data, progress, sequence, at }

        updateTimeline(e.checkpoint);

        if (e.is_terminal) {
            console.log('Workflow finished! Final job status:', e.job_status);
        }
    });
```

With **Livewire**:

```php
use Livewire\Attributes\On;

#[On('echo-private:n8n-job.{jobId},N8nQueueJobProgressUpdatedEvent')]
public function onProgress(array $data): void
{
    $this->timeline[] = $data['checkpoint'];
    $this->jobStatus  = $data['job_status'];

    if ($data['is_terminal']) {
        $this->isComplete = true;
    }
}
```

### 5. Auto-delete checkpoints on success

When a job completes successfully (`__done__`), the `N8nQueueJobObserver` automatically deletes all its checkpoints to keep the table lean. Configure via:

```env
N8N_BRIDGE_QUEUE_DELETE_CHECKPOINTS=true   # default: true
```

Set to `false` to retain checkpoints for all jobs (useful for auditing).

---

## Estimated duration & progress bar

The package maintains a **rolling exponential moving average (EMA)** of successful job durations per workflow. This allows you to show a time-based progress bar even without explicit `progress_percent` from n8n.

### How it works

1. Every time a job completes successfully, the observer calls `WorkflowDurationUpdater::record()`.
2. The EMA is updated: `new_ema = α × new_duration + (1 − α) × old_ema` where `α = 2 / (sample_count + 1)`.
3. The sample count is capped at `N8N_BRIDGE_QUEUE_DURATION_SAMPLES` (default: 50).

### Using estimated progress

```php
$job = N8nBridge::queue()->job($jobId);

// Returns 0-99 while running, 100 when done, null if no estimate yet
$percent = $job->estimatedProgressPercent();

// Workflow-level info
$workflow = $job->workflow;
echo $workflow->estimatedDurationLabel();    // "~2.3s" or "~1.5m"
echo $workflow->estimated_duration_ms;       // 2300
echo $workflow->estimated_sample_count;      // 47
```

### Combined progress (best of both)

Use explicit `progress_percent` from checkpoints when available, fall back to time-based estimate:

```php
$timeline   = N8nQueueCheckpoint::timelineForJob($jobId);
$lastStep   = end($timeline);
$fromSteps  = $lastStep['progress'] ?? null;
$fromTime   = $job->estimatedProgressPercent();

$displayPercent = $fromSteps ?? $fromTime ?? 0;
```

### Manual operations

```php
use Oriceon\N8nBridge\Queue\Workers\WorkflowDurationUpdater;

$updater = app(WorkflowDurationUpdater::class);

// Reset estimate (e.g. after redesigning the workflow)
$updater->reset($workflow);

// Recalculate from historical data (e.g. after importing jobs)
$updater->recalculate($workflow);
```

---

## Queue statistics

Queue jobs are included in the nightly statistics aggregation alongside inbound, outbound, and tool deliveries. Every day at `00:05`, `AggregateN8nStatsJob` reads `n8n__queue__jobs` grouped by `workflow_id` and writes one row per workflow per day into `n8n__stats__lists` with `direction = 'queue'`.

### What is tracked

| Stat column | Queue meaning |
|---|---|
| `total_count` | All jobs created that day |
| `success_count` | Jobs that reached `done` status |
| `failed_count` | Jobs in `failed` or `dead` state |
| `dlq_count` | Jobs that reached `dead` status (exhausted all retries) |
| `skipped_count` | Cancelled jobs |
| `avg_duration_ms` | Average duration of `done` jobs |
| `p95_duration_ms` | 95th percentile duration of `done` jobs |

### Querying queue stats

```php
use Oriceon\N8nBridge\Enums\DeliveryDirection;
use Oriceon\N8nBridge\Facades\N8nBridge;

// All queue stats for the last 30 days
$data = N8nBridge::stats()
    ->forWorkflow($workflow)
    ->direction(DeliveryDirection::Queue)
    ->lastDays(30)
    ->toChartData();

// Global queue overview (via raw model)
use Oriceon\N8nBridge\Models\N8nStat;

$queueStats = N8nStat::query()
    ->where('direction', DeliveryDirection::Queue->value)
    ->lastDays(7)
    ->get();
```

Queue stats use the same `direction` filter as delivery stats — pass `DeliveryDirection::Queue` to scope results to queue jobs only.

---

## Deleting done jobs

By default, done jobs are retained in `n8n__queue__jobs` and pruned after `prune_days` (default: 30 days). If you want to reclaim DB space sooner for high-throughput queues, enable `delete_done_jobs`:

```env
N8N_BRIDGE_QUEUE_DELETE_DONE_JOBS=true
N8N_BRIDGE_QUEUE_DONE_PRUNE_DAYS=1   # keep done jobs for 1 day (default)
```

### How it works

When `delete_done_jobs = true`, the scheduler registers an additional nightly prune at `01:00` that deletes `done` jobs older than `done_jobs_prune_days`:

```
00:05 — AggregateN8nStatsJob   → writes queue stats to n8n__stats__lists
01:00 — n8n:queue:prune --status=done --days=1   → deletes done jobs
```

The 1-day minimum ensures the stats aggregation at `00:05` has already captured all queue data before the rows are removed. **Do not set `done_jobs_prune_days` to `0`** — same-day deletion would cause the stats job to miss jobs completed that day.

### Manual done-job cleanup

```bash
# Preview what would be deleted
php artisan n8n:queue:prune --status=done --days=1 --dry-run

# Delete done jobs older than 1 day
php artisan n8n:queue:prune --status=done --days=1

# Delete done jobs older than 7 days
php artisan n8n:queue:prune --status=done --days=7
```

### Keeping done jobs

Set `delete_done_jobs = false` (the default) to retain done jobs for the full `prune_days` period. This is useful for:
- Auditing which jobs ran and what their `n8n_response` was
- Replaying jobs manually
- Debugging with full job history

---

## Artisan commands reference

```bash
# ── Worker ────────────────────────────────────────────────────────────────────
php artisan n8n:queue:work                       # Start worker (default queue)
php artisan n8n:queue:work --queue=critical      # Worker for specific queue
php artisan n8n:queue:work --sleep=2             # Poll interval in seconds
php artisan n8n:queue:work --max-jobs=100        # Stop after N jobs
php artisan n8n:queue:work --max-time=3600       # Stop after N seconds
php artisan n8n:queue:work --once                # Process one job and exit
php artisan n8n:queue:work --recover             # Recover stuck jobs and exit

# ── Status ────────────────────────────────────────────────────────────────────
php artisan n8n:queue:status                     # Overview of all queues
php artisan n8n:queue:status --queue=high        # Filter by queue name
php artisan n8n:queue:status --watch             # Refresh every 2 seconds (live)

# ── Retry ─────────────────────────────────────────────────────────────────────
php artisan n8n:queue:retry                      # Retry all dead jobs
php artisan n8n:queue:retry {uuid}               # Retry one specific job
php artisan n8n:queue:retry --workflow=name      # Filter by workflow name
php artisan n8n:queue:retry --reason=http_5xx    # Filter by failure reason
php artisan n8n:queue:retry --failed             # Include failed (not just dead)
php artisan n8n:queue:retry --reset-attempts     # Give full retry budget
php artisan n8n:queue:retry --priority=high      # Boost priority on retry
php artisan n8n:queue:retry --dry-run            # Preview without changes

# ── Cancel ────────────────────────────────────────────────────────────────────
php artisan n8n:queue:cancel {uuid}              # Cancel one job
php artisan n8n:queue:cancel --batch={uuid}      # Cancel entire batch
php artisan n8n:queue:cancel --workflow=name     # Cancel by workflow
php artisan n8n:queue:cancel --dry-run           # Preview without changes

# ── Prune ─────────────────────────────────────────────────────────────────────
php artisan n8n:queue:prune                      # Delete terminal jobs >30 days old
php artisan n8n:queue:prune --days=7             # Custom retention period
php artisan n8n:queue:prune --status=done        # Only prune one status
php artisan n8n:queue:prune --dry-run            # Preview without deleting
```

---

## Configuration reference

In `config/n8n-bridge.php`, under the `queue` key:

```php
'queue' => [
    'default_queue'    => env('N8N_BRIDGE_QUEUE_DEFAULT', 'default'),
    'lease_seconds'    => env('N8N_BRIDGE_QUEUE_LEASE', 150),
    'stuck_minutes'    => env('N8N_BRIDGE_QUEUE_STUCK_MINUTES', 10),
    'auto_prune'       => env('N8N_BRIDGE_QUEUE_AUTO_PRUNE', true),
    'prune_days'       => env('N8N_BRIDGE_QUEUE_PRUNE_DAYS', 30),
    'log_channel'      => env('N8N_BRIDGE_QUEUE_LOG_CHANNEL', 'stack'),

    // Progress tracking
    'progress_route_prefix'         => env('N8N_BRIDGE_QUEUE_PROGRESS_PREFIX', 'n8n/queue/progress'),
    'progress_api_key'              => env('N8N_BRIDGE_QUEUE_PROGRESS_KEY'),
    'delete_checkpoints_on_success' => env('N8N_BRIDGE_QUEUE_DELETE_CHECKPOINTS', true),

    // Done job retention
    // When true, done jobs are pruned after `done_jobs_prune_days` days (at 01:00,
    // after the 00:05 stats aggregation). When false, done jobs are retained for
    // the standard `prune_days` period alongside dead/cancelled jobs.
    'delete_done_jobs'     => env('N8N_BRIDGE_QUEUE_DELETE_DONE_JOBS', false),
    'done_jobs_prune_days' => env('N8N_BRIDGE_QUEUE_DONE_PRUNE_DAYS', 1),

    // Duration EMA
    'duration_sample_size' => env('N8N_BRIDGE_QUEUE_DURATION_SAMPLES', 50),

    // Per-priority retry delays
    'retry_delays' => [
        'critical' => 10,
        'high'     => 15,
        'normal'   => 30,
        'low'      => 60,
        'bulk'     => 120,
    ],
],
```

---

## Events fired

| Event                             | When | Payload |
|-----------------------------------|---|---|
| `N8nQueueJobStartedEvent`         | Worker claims a job | `$job` |
| `N8nQueueJobCompletedEvent`       | Job finishes successfully | `$job` |
| `N8nQueueJobFailedEvent`          | A single attempt fails | `$job`, `$failure` |
| `N8nQueueBatchCompletedEvent`     | All jobs in a batch are terminal | `$batch` |
| `N8nQueueJobProgressUpdatedEvent` | n8n sends a checkpoint | `$job`, `$checkpoint` — **also broadcasts** |

All events are in namespace `Oriceon\N8nBridge\Events`.

`N8nQueueJobProgressUpdatedEvent` implements `ShouldBroadcast` and broadcasts on the private channel `n8n-job.{jobId}` with event name `QueueJobProgressUpdated`.
