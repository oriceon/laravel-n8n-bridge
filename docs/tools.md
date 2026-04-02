![Laravel N8N Bridge](images/banner.png)

# 🔧 Tools — n8n calls Laravel

← [Back to README](../README.md)

Tools expose your application's data and actions to n8n workflows. n8n makes HTTP requests to `/n8n/tools/{name}` and uses the response directly in subsequent nodes.

Tools support **all HTTP methods** — use GET for data retrieval, POST for actions, PATCH/DELETE for mutations. One handler class, all methods you choose.

---

## Concept

```
n8n workflow
  Node 1: GET  https://myapp.com/n8n/tools/invoices?filter[status]=paid
           → { "data": [...], "meta": { "total": 47 } }
  Node 2: GET  https://myapp.com/n8n/tools/invoices/42
           → { "data": { "id": 42, "customer": {...} } }
  Node 3: POST https://myapp.com/n8n/tools/send-invoice
           body: { "invoice_id": 42 }
           → { "data": { "sent": true, "to": "john@example.com" } }
  Node 4: PATCH https://myapp.com/n8n/tools/contacts/99
           body: { "linkedin_url": "..." }
           → { "data": { "updated": true } }
```

---

## 1. Create a handler

```bash
php artisan make:n8n-tool InvoicesTool
```

Creates `app/N8n/Tools/InvoicesTool.php`:

```php
<?php

namespace App\N8n\Tools;

use Oriceon\N8nBridge\DTOs\N8nToolRequest;
use Oriceon\N8nBridge\DTOs\N8nToolResponse;
use Oriceon\N8nBridge\Tools\N8nToolHandler;

final class InvoicesTool extends N8nToolHandler
{
    /**
     * GET /n8n/tools/invoices
     * Supports: ?filter[status]=paid&search=john&sort=-created_at&per_page=50
     */
    public function get(N8nToolRequest $request): N8nToolResponse
    {
        return N8nToolResponse::paginated(
            Invoice::query()
                ->when($request->filter('status'),      fn($q, $v) => $q->where('status', $v))
                ->when($request->filter('customer_id'), fn($q, $v) => $q->where('customer_id', $v))
                ->when($request->filterDate('from'),    fn($q, $v) => $q->where('issued_at', '>=', $v))
                ->when($request->search(),              fn($q, $v) => $q->where('number', 'like', "%{$v}%"))
                ->paginate($request->perPage()),
            fn(Invoice $i) => [
                'id'       => $i->id,
                'number'   => $i->invoice_number,
                'amount'   => $i->total,
                'status'   => $i->status,
                'issued_at' => $i->issued_at?->toIso8601String(),
            ]
        );
    }

    /**
     * GET /n8n/tools/invoices/{id}
     */
    public function getById(N8nToolRequest $request, string|int $id): N8nToolResponse
    {
        $invoice = Invoice::with('customer', 'items')->find($id);

        return $invoice
            ? N8nToolResponse::item($invoice, fn($i) => [
                'id'       => $i->id,
                'number'   => $i->invoice_number,
                'customer' => ['id' => $i->customer->id, 'email' => $i->customer->email],
                'items'    => $i->items->map(fn($item) => [
                    'description' => $item->description,
                    'total'       => $item->qty * $item->unit_price,
                ])->all(),
            ])
            : N8nToolResponse::notFound("Invoice [{$id}] not found.");
    }

    /**
     * POST /n8n/tools/invoices — send an invoice
     */
    public function post(N8nToolRequest $request): N8nToolResponse
    {
        $invoice = Invoice::findOrFail($request->required('invoice_id'));
        Mail::to($invoice->customer->email)->send(new InvoiceMail($invoice));

        return N8nToolResponse::success([
            'sent' => true,
            'to'   => $invoice->customer->email,
        ]);
    }

    /**
     * PATCH /n8n/tools/invoices/{id} — partial update from n8n
     */
    public function patch(N8nToolRequest $request, string|int $id): N8nToolResponse
    {
        $invoice = Invoice::findOrFail($id);
        $invoice->update(array_filter([
            'smartbill_id' => $request->get('smartbill_id'),
            'pdf_url'      => $request->get('pdf_url'),
        ]));
        return N8nToolResponse::success(['updated' => true]);
    }
}
```

---

## 2. Register the tool

```bash
php artisan n8n:tool:create invoices \
  --handler="App\N8n\Tools\InvoicesTool" \
  --methods=GET,POST,PATCH \
  --label="Invoices" \
  --category=billing \
  --rate-limit=120
```

---

## 3. Attach a credential for authentication

```bash
# Create webhook (once per n8n instance)
php artisan n8n:credential:create "Production"

# Attach to this tool
php artisan n8n:credential:attach {credential-id} --tool=invoices

# Or attach to everything at once
php artisan n8n:credential:attach {credential-id} --all
```

---

## 4. Use in n8n

### GET — fetch list with filters

```
HTTP Request node:
  Method:  GET
  URL:     https://myapp.com/n8n/tools/invoices
  Query:   filter[status]      = paid
           filter[customer_id] = {{ $json.customer_id }}
           per_page            = 100
           sort                = -created_at
  Header:  X-N8N-Key: n8br_sk_...
```

Response in n8n:
- `{{ $json.data }}` — array of invoices
- `{{ $json.data[0].number }}` — first invoice number
- `{{ $json.meta.total }}` — total count
- `{{ $json.meta.has_more }}` — more pages available

### GET by ID

```
Method: GET
URL:    https://myapp.com/n8n/tools/invoices/{{ $json.invoice_id }}
Header: X-N8N-Key: n8br_sk_...
```

Response: `{{ $json.data.customer.email }}`

### POST — action

```
Method: POST
URL:    https://myapp.com/n8n/tools/invoices
Body:   { "invoice_id": "{{ $json.id }}" }
Header: X-N8N-Key: n8br_sk_...
```

### PATCH — partial update

```
Method: PATCH
URL:    https://myapp.com/n8n/tools/invoices/{{ $json.id }}
Body:   { "smartbill_id": "{{ $json.sb_id }}", "pdf_url": "{{ $json.url }}" }
Header: X-N8N-Key: n8br_sk_...
```

---

## 5. HTTP method → handler method

| HTTP | Route | Handler method |
|---|---|---|
| `GET` | `/n8n/tools/{name}` | `get(request)` |
| `GET` | `/n8n/tools/{name}/{id}` | `getById(request, id)` |
| `POST` | `/n8n/tools/{name}` | `post(request)` |
| `PUT` | `/n8n/tools/{name}/{id}` | `put(request, id)` |
| `PATCH` | `/n8n/tools/{name}/{id}` | `patch(request, id)` |
| `DELETE` | `/n8n/tools/{name}/{id}` | `delete(request, id)` |

Unimplemented methods return `405 Method Not Allowed` automatically.

Control which methods n8n can use via `--methods`:
```bash
--methods=GET          # read-only
--methods=GET,POST     # read + create/action
--methods=GET,POST,PATCH,DELETE  # full CRUD
```

`null` (default) = POST only (backward compat with pre-existing tools).

---

## 6. N8nToolRequest helpers

```php
// Direct access (body or query params)
$request->get('field', 'default')
$request->required('field')          // throws if missing
$request->getString('field')
$request->getInt('field', 0)
$request->getFloat('field', 0.0)
$request->getBool('field', false)
$request->getArray('field', [])
$request->getCarbon('field')         // Carbon instance

// REST query helpers (GET requests)
$request->filters()                  // ['status' => 'paid', ...]
$request->filter('status')
$request->filterInt('customer_id')
$request->filterBool('is_active')
$request->filterDate('from')         // Carbon
$request->filterArray('tags')        // ['php', 'laravel']
$request->search()                   // ?search=john
$request->sorts()                    // [['field'=>'name','direction'=>'asc']]
$request->applySorts($query, ['name', 'created_at'])
$request->perPage(default: 15, max: 100)
$request->page()
$request->fields()                   // ['id', 'name', 'status']
$request->includes()                 // ['customer', 'items']
$request->wants('customer')          // true/false

// Meta
$request->method()                   // 'GET', 'POST', etc.
$request->isGet() / isPost() / isPatch() / isDelete()
$request->toolName()
$request->callerWorkflowId()         // X-N8N-Workflow-Id header
```

---

## 7. N8nToolResponse shapes

```php
// Single record
return N8nToolResponse::item($model, fn($m) => ['id' => $m->id, ...]);
// { "data": { ...fields } }

// List (no pagination)
return N8nToolResponse::collection($items, fn($i) => [...]);
// { "data": [ {...}, {...} ] }

// Paginated list
return N8nToolResponse::paginated(Model::paginate($request->perPage()), fn($m) => [...]);
// { "data": [...], "meta": { "total": 100, "per_page": 15, "has_more": true, ... } }

// Action success
return N8nToolResponse::success(['sent' => true, 'to' => $email]);
return N8nToolResponse::success($model->toArray(), 'Created successfully.');
// { "data": { "sent": true, "to": "..." } }

// Empty result
return N8nToolResponse::empty();
// { "data": [] }

// Error responses
return N8nToolResponse::notFound("Invoice [42] not found.");   // 404
return N8nToolResponse::error("Validation failed.", 422);      // any 4xx
return N8nToolResponse::unauthorized("Invalid token.");        // 401

// Extra metadata
return N8nToolResponse::collection($items)->withMeta(['currency' => 'RON']);
// { "data": [...], "meta": { "currency": "RON" } }
```

---

## 8. OpenAPI schema

```
GET /n8n/tools/schema
```

Returns a full OpenAPI 3 schema for all active tools — import into n8n for autocomplete. The schema is public (no auth required).

For GET tools, the schema automatically includes all standard query parameters: `filter`, `search`, `sort`, `per_page`, `page`, `fields`, `include`.

---

## 9. Artisan commands

```bash
# Create tool
php artisan n8n:tool:create invoices \
  --handler="App\N8n\Tools\InvoicesTool" \
  --methods=GET,POST,PATCH \
  --label="Invoices" \
  --category=billing

# List tools
php artisan n8n:tool:list

# Generate handler stub
php artisan make:n8n-tool InvoicesTool
php artisan make:n8n-tool Contacts/ContactsTool

# Attach webhook (for auth)
php artisan n8n:credential:attach {id} --tool=invoices
php artisan n8n:credential:attach {id} --all
```

---

## 10. Full CRUD example — Contacts

```php
final class ContactsTool extends N8nToolHandler
{
    public function get(N8nToolRequest $request): N8nToolResponse
    {
        $query = Contact::query()->with('company', 'tags')
            ->when($request->filter('company_id'), fn($q, $v) => $q->where('company_id', $v))
            ->when($request->filter('country'),    fn($q, $v) => $q->where('country', $v))
            ->when($request->search(),             fn($q, $v) => $q->where('name', 'like', "%{$v}%"));

        $request->applySorts($query, ['name', 'created_at']);

        return N8nToolResponse::paginated(
            $query->paginate($request->perPage(50)),
            fn(Contact $c) => [
                'id'      => $c->id,
                'name'    => $c->name,
                'email'   => $c->email,
                'company' => $c->company?->name,
                'tags'    => $c->tags->pluck('name')->all(),
            ]
        );
    }

    public function getById(N8nToolRequest $request, string|int $id): N8nToolResponse
    {
        $contact = Contact::with('company', 'notes')->find($id);
        return $contact
            ? N8nToolResponse::item($contact)
            : N8nToolResponse::notFound("Contact [{$id}] not found.");
    }

    public function patch(N8nToolRequest $request, string|int $id): N8nToolResponse
    {
        Contact::findOrFail($id)->update(array_filter([
            'linkedin_url' => $request->get('linkedin_url'),
            'company_size' => $request->get('company_size'),
            'enriched_at'  => now(),
        ]));
        return N8nToolResponse::success(['updated' => true]);
    }
}
```

Register with `--methods=GET,PATCH`:

```bash
php artisan n8n:tool:create contacts \
  --handler="App\N8n\Tools\ContactsTool" \
  --methods=GET,PATCH \
  --label="Contacts" \
  --category=crm
```
