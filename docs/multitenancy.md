![Laravel N8N Bridge](images/banner.png)

# 🏢 Multi-Instance & Multi-Tenancy

← [Back to README](../README.md)

---

## Multiple n8n instances

You can connect to multiple n8n instances — for example, one per environment or one per business unit.

### Configuration

```php
// config/n8n-bridge.php
'instances' => [
    'default' => [
        'api_base_url'      => env('N8N_BRIDGE_N8N_DEFAULT_API_BASE_URL', 'http://localhost:5678'),
        'api_key'           => env('N8N_BRIDGE_N8N_DEFAULT_API_KEY'),
        'webhook_base_url'  => env('N8N_BRIDGE_N8N_DEFAULT_WEBHOOK_BASE_URL', 'http://localhost:5678/webhook'),
        'timeout'           => env('N8N_BRIDGE_N8N_DEFAULT_TIMEOUT', 30),
    ],
    'staging' => [
        'api_base_url'      => env('N8N_BRIDGE_N8N_STAGING_API_BASE_URL'),
        'api_key'           => env('N8N_BRIDGE_N8N_STAGING_API_KEY'),
        'webhook_base_url'  => env('N8N_BRIDGE_N8N_STAGING_WEBHOOK_BASE_URL'),
        'timeout'           => env('N8N_BRIDGE_N8N_STAGING_TIMEOUT', 30),
    ],
    'eu-production' => [
        'api_base_url'      => env('N8N_BRIDGE_N8N_EU_API_BASE_URL'),
        'api_key'           => env('N8N_BRIDGE_N8N_EU_API_KEY'),
        'webhook_base_url'  => env('N8N_BRIDGE_N8N_EU_WEBHOOK_BASE_URL'),
        'timeout'           => env('N8N_BRIDGE_N8N_EU_TIMEOUT', 60),
    ],
],
```

### Using a specific instance

```php
// Access client for a specific instance
$client = N8nBridge::client('eu-production');
$client->listWorkflows();

// Trigger workflow on a specific instance
$workflow = N8nWorkflow::query()
    ->forInstance('eu-production')
    ->where('name', 'invoice-paid')
    ->firstOrFail();

N8nBridge::trigger($workflow, $payload);
```

### Sync workflows per instance

```bash
php artisan n8n:workflows:sync --instance=default
php artisan n8n:workflows:sync --instance=eu-production
```

### Queue jobs to a specific instance

```php
QueueDispatcher::workflow('invoice-paid')
    ->payload($data)
    ->instance('eu-production')
    ->dispatch();
```

---

## Workflow ownership (morphs)

Workflows support a nullable polymorphic `owner` relationship. Use this to scope workflows to tenants, companies, or users.

```php
// Assign a workflow to a tenant
$workflow->update([
    'owner_type' => Company::class,
    'owner_id'   => $company->id,
]);

// Query by owner
N8nWorkflow::query()
    ->whereMorphedTo('owner', $company)
    ->active()
    ->get();

// Or using the standard morph scope
N8nWorkflow::where('owner_type', Company::class)
    ->where('owner_id', $company->id)
    ->get();
```

---

## Table prefix per tenant

If you run multiple Laravel applications sharing a database, use different table prefixes:

**App 1:**
```env
N8N_BRIDGE_TABLE_PREFIX=crm
# → crm__workflows__lists, crm__endpoints__lists, crm__queue__jobs ...
```

**App 2:**
```env
N8N_BRIDGE_TABLE_PREFIX=erp
# → erp__workflows, erp__endpoints, erp__queue__jobs ...
```

The prefix is resolved at runtime from config — no code changes needed between tenants.

---

## Per-tenant endpoint routing (domain-based)

If you use `BrandForDomainMiddleware` or a similar pattern to resolve the tenant from the request host, you can combine it with the package's inbound routing:

```php
// In a custom middleware that runs before the package routes:
public function handle(Request $request, Closure $next): Response
{
    $company = Company::where('domain', $request->getHost())->firstOrFail();
    app()->instance('current_company', $company);
    return $next($request);
}
```

Then in your handler:

```php
final class InvoicePaidHandler extends N8nInboundHandler
{
    public function handle(N8nPayload $payload): void
    {
        $company = app('current_company');
        // scope all queries to $company
    }
}
```

---

## Multi-tenant queue

When running multiple tenants through the same queue worker, add a `context` to each job for traceability:

```php
QueueDispatcher::workflow('invoice-paid')
    ->payload($payload)
    ->context([
        'tenant_id'   => $tenant->id,
        'tenant_slug' => $tenant->slug,
    ])
    ->onQueue("tenant-{$tenant->id}")   // optionally isolate per tenant
    ->dispatch();
```

The `context` field is stored with the job and all its failure records — visible in `n8n:queue:status` and failure logs.
