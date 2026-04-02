![Laravel N8N Bridge](images/banner.png)

# 📊 Statistics

← [Back to README](../README.md)

The package aggregates daily delivery statistics per workflow. Use them for dashboards, alerting, and SLA reporting.

---

## How aggregation works

A scheduled job runs at `00:05` every night via Laravel Scheduler:

```
AggregateN8nStatsJob
    → reads N8nDelivery for yesterday   → groups by workflow_id + direction
    → reads N8nQueueJob for yesterday   → groups by workflow_id
    → calculates: total, success, failed, avg_duration_ms, p95_duration_ms, error_rate
    → upserts into n8n__stats__lists
```

Stats are stored in `n8n__stats__lists` — one row per workflow per day per direction. The raw delivery and queue records remain untouched.

Make sure the scheduler is running:

```bash
# crontab -e
* * * * * cd /var/www && php artisan schedule:run >> /dev/null 2>&1
```

---

## Directions

Each stat row has a `direction` that identifies the traffic source:

| Direction | Source | Description |
|---|---|---|
| `inbound` | `N8nDelivery` | n8n → Laravel (inbound webhooks) |
| `outbound` | `N8nDelivery` | Laravel → n8n (outbound triggers) |
| `tool` | `N8nDelivery` | n8n calling Laravel tool endpoints |
| `queue` | `N8nQueueJob` | Laravel DB queue jobs dispatched to n8n |

Queue stats capture the throughput and health of the DB queue system — how many jobs were created, how many completed successfully, and how many ended up in the DLQ.

---

## Querying stats

### Global overview

```php
use Oriceon\N8nBridge\Facades\N8nBridge;

$overview = N8nBridge::stats()->overview();

// Returns:
// [
//   'total_deliveries' => 14823,
//   'success_rate'     => 98.4,      // percent
//   'avg_duration_ms'  => 142,
//   'dlq_pending'      => 7,
//   'failed_today'     => 3,
// ]
```

### Per workflow, last N days

```php
$data = N8nBridge::stats()
    ->forWorkflow($workflow)
    ->lastDays(30)
    ->toChartData();

// Returns:
// [
//   'labels'       => ['2026-02-20', '2026-02-21', ...],
//   'success'      => [142, 156, ...],
//   'failed'       => [0, 2, ...],
//   'success_rate' => [100.0, 98.7, ...],
// ]
```

### Queue stats only

```php
use Oriceon\N8nBridge\Enums\DeliveryDirection;

$queueData = N8nBridge::stats()
    ->forWorkflow($workflow)
    ->direction(DeliveryDirection::Queue)
    ->lastDays(30)
    ->toChartData();
```

### Custom date range

```php
$data = N8nBridge::stats()
    ->forWorkflow($workflow)
    ->between(Carbon::parse('2026-01-01'), Carbon::parse('2026-03-01'))
    ->period(StatPeriod::Weekly)
    ->toChartData();
```

### Top failing workflows

```php
$problematic = N8nBridge::stats()->topFailed(limit: 10);

// Returns collection of N8nWorkflow with appended stats:
// $workflow->failed_count, $workflow->error_rate, $workflow->last_failure_at
```

### Artisan

```bash
php artisan n8n:stats
php artisan n8n:stats --last=30
```

---

## N8nStat model

```php
use Oriceon\N8nBridge\Models\N8nStat;

$stat = N8nStat::query()
    ->where('workflow_id', $workflow->id)
    ->where('date', today()->subDay())
    ->first();

$stat->total_count;       // total deliveries that day
$stat->success_count;     // successful
$stat->failed_count;      // failed
$stat->avg_duration_ms;   // average
$stat->p95_duration_ms;   // 95th percentile
$stat->error_rate;        // failed / total * 100

// Computed helpers
$stat->successRate();     // float percent
$stat->failureRate();     // float percent
```

---

## High error rate alerts

When `AggregateN8nStatsJob` runs, it compares the error rate against the configured threshold and fires an alert if exceeded:

```env
N8N_BRIDGE_NOTIFY_ERROR_RATE=20.0   # alert when error rate > 20%
```

The alert includes the workflow name, error rate, and period.

---

## Queue stat columns

When `direction = 'queue'`, the stat columns map to queue-specific concepts:

| Column | Queue meaning |
|---|---|
| `total_count` | All jobs created that day |
| `success_count` | Jobs that reached `done` |
| `failed_count` | Jobs in `failed` or `dead` state |
| `dlq_count` | Jobs that reached `dead` (exhausted retries) |
| `skipped_count` | Cancelled jobs |
| `avg_duration_ms` | Average duration of `done` jobs |
| `p95_duration_ms` | 95th percentile duration of `done` jobs |

---

## StatPeriod enum

| Value | Description |
|---|---|
| `Hourly` | Group by hour (for `between()` queries) |
| `Daily` | Group by day (default) |
| `Weekly` | Group by ISO week |
| `Monthly` | Group by month |

---

## Retention

Stats rows are kept indefinitely by default. You can prune manually:

```php
use Oriceon\N8nBridge\Models\N8nStat;

// Delete stats older than 1 year
N8nStat::where('date', '<', now()->subYear())->delete();
```
