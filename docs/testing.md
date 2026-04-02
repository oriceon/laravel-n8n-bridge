![Laravel N8N Bridge](images/banner.png)

# 🧪 Testing Your Integration

← [Back to README](../README.md)

How to write tests for your own handlers, tools, and queue jobs that use this package.

---

## Setup

The package ships with an in-memory SQLite `TestCase`. Extend it in your own tests or set up migrations manually.

### Using the package TestCase (simplest)

```php
use Oriceon\N8nBridge\Tests\TestCase;

class InvoicePaidHandlerTest extends TestCase
{
    // Migrations run automatically via defineDatabaseMigrations()
}
```

### Using your own TestCase

Make sure package migrations run:

```php
protected function defineDatabaseMigrations(): void
{
    $this->loadMigrationsFrom(__DIR__ . '/../../vendor/oriceon/laravel-n8n-bridge/database/migrations');
    $this->artisan('migrate');
}
```

---

## Testing inbound handlers

Test your handler logic directly by constructing an `N8nPayload`:

```php
use Oriceon\N8nBridge\DTOs\N8nPayload;
use App\N8n\InvoicePaidHandler;

it('marks invoice as paid', function () {
    $invoice = Invoice::factory()->create(['status' => 'pending']);

    $payload = new N8nPayload([
        'invoice_id' => $invoice->id,
        'amount'     => 150.00,
        'paid_at'    => '2026-03-22T10:00:00Z',
    ]);

    $handler = new InvoicePaidHandler();
    $handler->handle($payload);

    expect($invoice->fresh()->status)->toBe('paid')
        ->and($invoice->fresh()->amount)->toBe(150.00);
});
```

### Test validation rules

```php
use Oriceon\N8nBridge\DTOs\N8nPayload;

it('fails validation when invoice_id is missing', function () {
    $handler = new InvoicePaidHandler();

    expect(fn () => $handler->handle(new N8nPayload(['amount' => 100])))
        ->toThrow(\Illuminate\Validation\ValidationException::class);
});
```

---

## Testing inbound HTTP endpoint (integration)

```php
use Oriceon\N8nBridge\Models\N8nApiKey;use Oriceon\N8nBridge\Models\N8nEndpoint;

it('accepts a valid inbound request and dispatches job', function () {
    Queue::fake();

    $endpoint = N8nEndpoint::factory()->create([
        'slug'          => 'invoice-paid',
        'handler_class' => InvoicePaidHandler::class,
    ]);
    [$plaintext, $apiKey] = N8nApiKey::generate($endpoint);

    $this->postJson('/n8n/in/invoice-paid', ['invoice_id' => 42], [
        'X-N8N-Key' => $plaintext,
    ])->assertStatus(202);

    Queue::assertPushed(\Oriceon\N8nBridge\Jobs\ProcessN8nInboundJob::class);
});
```

---

## Testing tool handlers

```php
use Oriceon\N8nBridge\DTOs\N8nToolRequest;
use App\N8n\Tools\GetContactTool;

it('returns contact data', function () {
    $contact = Contact::factory()->create(['email' => 'test@example.com']);

    $request  = new N8nToolRequest(['email' => 'test@example.com']);
    $response = (new GetContactTool())->handle($request);

    expect($response->isSuccess())->toBeTrue()
        ->and($response->data['name'])->toBe($contact->name);
});

it('returns not found for unknown email', function () {
    $request  = new N8nToolRequest(['email' => 'nobody@example.com']);
    $response = (new GetContactTool())->handle($request);

    expect($response->isSuccess())->toBeFalse()
        ->and($response->type->value)->toBe('not_found');
});
```

---

## Testing queue dispatch

```php
use Oriceon\N8nBridge\Enums\QueueJobStatus;use Oriceon\N8nBridge\Models\N8nQueueJob;use Oriceon\N8nBridge\Queue\QueueDispatcher;

it('dispatches a job with correct priority', function () {
    $workflow = N8nWorkflow::factory()->create(['name' => 'invoice-paid']);

    $job = QueueDispatcher::workflow('invoice-paid')
        ->payload(['invoice_id' => 42])
        ->high()
        ->dispatch();

    expect($job)->toBeInstanceOf(N8nQueueJob::class)
        ->and($job->status)->toBe(QueueJobStatus::Pending)
        ->and($job->priority->value)->toBe(75);
});
```

### Test idempotency

```php
it('returns existing job for duplicate idempotency key', function () {
    $workflow = N8nWorkflow::factory()->create();

    $job1 = QueueDispatcher::workflow($workflow)->payload([])->idempotent('key-123')->dispatch();
    $job2 = QueueDispatcher::workflow($workflow)->payload([])->idempotent('key-123')->dispatch();

    expect($job1->id)->toBe($job2->id)
        ->and(N8nQueueJob::count())->toBe(1);
});
```

---

## Testing progress checkpoints

```php
use Oriceon\N8nBridge\Models\N8nQueueCheckpoint;

it('stores checkpoint from progress endpoint', function () {
    config(['n8n-bridge.queue.progress_api_key' => 'test-key']);

    $job = N8nQueueJob::factory()->create(['status' => 'running']);

    $this->postJson("/n8n/queue/progress/{$job->id}", [
        'node'    => 'send_email',
        'status'  => 'completed',
        'message' => 'Sent',
    ], ['X-N8N-Key' => 'test-key'])->assertStatus(201);

    expect(N8nQueueCheckpoint::where('job_id', $job->id)->count())->toBe(1);
});
```

---

## Faking outbound HTTP calls

The package selects either `/webhook/` or `/webhook-test/` based on `webhook_mode` and `APP_ENV`. Use a wildcard pattern that matches both URL variants:

```php
use Illuminate\Support\Facades\Http;

it('triggers workflow and records delivery', function () {
    // '*' matches both /webhook/ and /webhook-test/
    Http::fake(['*' => Http::response(['executionId' => 123], 200)]);

    $delivery = N8nBridge::trigger('order-shipped', ['order_id' => 1]);

    expect($delivery->status->value)->toBe('done')
        ->and($delivery->n8n_execution_id)->toBe(123);
});
```

To match a specific host while allowing both webhook URL variants, use `*/webhook*/*`:

```php
Http::fake([
    'n8n.test:5678/webhook*/*' => Http::response(['executionId' => 123], 200),
]);
```

> Do **not** use `n8n.test:5678/webhook/*` — this only matches `/webhook/` paths, not `/webhook-test/` paths.

### Simulate n8n returning 500

```php
Http::fake(['*' => Http::response('Server Error', 500)]);

$delivery = N8nBridge::trigger('order-shipped', ['order_id' => 1]);

expect($delivery->status->value)->toBe('failed');
```

### Force a specific webhook mode in tests

```php
beforeEach(function () {
    $this->workflow = N8nWorkflow::factory()->create([
        'webhook_mode' => WebhookMode::Production, // always use /webhook in tests
    ]);
});
```

---

## Disabling notifications in tests

In `TestCase::getEnvironmentSetUp()`:

```php
$app['config']->set('n8n-bridge.notifications.enabled', false);
```

Or use `Notification::fake()` if you want to assert on what would have been sent.

---

## Useful assertions

```php
// Assert a queue job was created for a workflow
expect(N8nQueueJob::where('workflow_id', $workflow->id)->count())->toBe(1);

// Assert job is pending with correct priority
$job = N8nQueueJob::first();
expect($job->status)->toBe(QueueJobStatus::Pending)
    ->and($job->priority)->toBe(QueueJobPriority::High);

// Assert checkpoint timeline
$timeline = N8nQueueCheckpoint::timelineForJob($job->id);
expect($timeline)->toHaveCount(3)
    ->and($timeline[0]['status'])->toBe('completed');

// Assert circuit breaker stayed closed
$breaker = $workflow->circuitBreaker;
expect($breaker)->toBeNull(); // never opened
// or
expect($breaker->state)->toBe(CircuitBreakerState::Closed);
```
