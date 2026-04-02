![Laravel N8N Bridge](images/banner.png)

# 🔄 Circuit Breaker

← [Back to README](../README.md)

The circuit breaker protects your application from cascading failures when an n8n workflow or instance becomes unavailable. It tracks consecutive failures per workflow and temporarily blocks requests until the system recovers.

---

## State machine

```
         N failures
Closed ──────────────────► Open
  ▲                           │
  │  2× consecutive success   cooldown elapsed
  │                           │
  └────── HalfOpen ◄──────────┘
               │
               └── failure ──► Open (reset cooldown)
```

| State | Meaning | Requests allowed |
|---|---|---|
| `Closed` | Normal operation | ✅ All |
| `Open` | Too many failures | ❌ Blocked |
| `HalfOpen` | Testing recovery | ✅ Probe requests (2 consecutive successes required to close) |

---

## Default thresholds

Configured in `config/n8n-bridge.php`:

```php
'circuit_breaker' => [
    'failure_threshold' => 5,    // consecutive failures to open
    'cooldown_seconds'  => 60,   // seconds before Open → HalfOpen
],
```

> **HalfOpen probe logic:** after the cooldown expires, the circuit enters HalfOpen and allows requests through. The first successful response increments an internal `success_count`. After **2 consecutive successes** the circuit closes and `success_count` resets to 0. A single failure in HalfOpen immediately re-opens the circuit and restarts the cooldown.

Override per environment:

```env
N8N_BRIDGE_CB_THRESHOLD=5
N8N_BRIDGE_CB_COOLDOWN=60
```

---

## Usage in your code

The circuit breaker runs automatically inside `N8nOutboundDispatcher` and `QueueWorker`. You rarely need to interact with it directly, but you can:

```php
use Oriceon\N8nBridge\CircuitBreaker\CircuitBreakerManager;
use Oriceon\N8nBridge\Enums\CircuitBreakerState;

$cb = app(CircuitBreakerManager::class);

// Check current state
$state = $cb->getState($workflow);

if (! $state->allowsRequests()) {
    // Circuit is Open — skip or queue for later
    return;
}

// Record outcome manually (if you have custom HTTP logic)
$cb->recordSuccess($workflow);
$cb->recordFailure($workflow);

// Manually reset (e.g. after fixing n8n)
$cb->reset($workflow);
```

### CircuitBreakerState methods

```php
$state->allowsRequests();  // true for Closed and HalfOpen
$state->isOpen();
$state->isClosed();
$state->isHalfOpen();
```

---

## DB record

Each workflow has at most one `N8nCircuitBreaker` record:

```php
use Oriceon\N8nBridge\Models\N8nCircuitBreaker;

$breaker = $workflow->circuitBreaker;

$breaker->state;            // CircuitBreakerState enum
$breaker->failure_count;    // consecutive failures
$breaker->success_count;    // consecutive successes while in HalfOpen
$breaker->opened_at;        // when it opened
$breaker->half_open_at;     // when cooldown elapsed
$breaker->last_failure_at;
$breaker->last_success_at;
```

---

## Events

| Event                          | When | Payload |
|--------------------------------|---|---|
| `N8nCircuitBreakerOpenedEvent` | State transitions to Open | `$workflow`, `$failureCount` |

```php
use Oriceon\N8nBridge\Events\N8nCircuitBreakerOpenedEvent;

Event::listen(N8nCircuitBreakerOpenedEvent::class, function ($event) {
    Log::critical('Circuit breaker opened for: ' . $event->workflow->name, [
        'failures' => $event->failureCount,
    ]);
});
```

An alert is also automatically sent via the configured notification channels (Slack, mail, etc.).

---

## Interaction with the DB queue

When the circuit breaker is Open, the `QueueWorker` releases the job back to the queue with `QueueFailureReason::CircuitBreakerOpen` — the job is **not** counted as a failed attempt. It simply waits until the circuit recovers.

```
Worker claims job
    → CB is Open
    → job.status stays pending, available_at = now() + 60s
    → attempt count NOT incremented
    → job waits for CB cooldown to pass
```
