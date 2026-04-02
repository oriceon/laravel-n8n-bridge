<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Tools;

use Oriceon\N8nBridge\DTOs\N8nToolRequest;
use Oriceon\N8nBridge\DTOs\N8nToolResponse;

/**
 * Abstract base for all /n8n/tools/{name} handlers.
 *
 * Override only the HTTP methods you want to expose.
 * Unimplemented methods return 405 Method Not Allowed automatically.
 *
 * ---
 *
 * Read-only tool (GET only):
 *
 *   #[AsN8nTool('invoices')]
 *   final class InvoicesTool extends N8nToolHandler
 *   {
 *       public function get(N8nToolRequest $request): N8nToolResponse
 *       {
 *           return N8nToolResponse::paginated(
 *               Invoice::query()
 *                   ->when($request->filter('status'), fn($q, $v) => $q->where('status', $v))
 *                   ->when($request->search(), fn($q, $v) => $q->where('number', 'like', "%{$v}%"))
 *                   ->paginate($request->perPage()),
 *               fn($i) => ['id' => $i->id, 'number' => $i->invoice_number, 'amount' => $i->total]
 *           );
 *       }
 *
 *       public function getById(N8nToolRequest $request, string|int $id): N8nToolResponse
 *       {
 *           $invoice = Invoice::find($id);
 *           return $invoice
 *               ? N8nToolResponse::item($invoice)
 *               : N8nToolResponse::notFound("Invoice [{$id}] not found.");
 *       }
 *   }
 *
 * Action tool (POST):
 *
 *   final class SendInvoiceTool extends N8nToolHandler
 *   {
 *       public function post(N8nToolRequest $request): N8nToolResponse
 *       {
 *           $invoice = Invoice::findOrFail($request->required('invoice_id'));
 *           Mail::to($invoice->customer->email)->send(new InvoiceMail($invoice));
 *           return N8nToolResponse::success(['sent' => true, 'to' => $invoice->customer->email]);
 *       }
 *   }
 *
 * Full CRUD tool:
 *
 *   final class ContactsTool extends N8nToolHandler
 *   {
 *       public function get(N8nToolRequest $request): N8nToolResponse    { ... } // list
 *       public function getById(N8nToolRequest $request, $id): N8nToolResponse { ... } // show
 *       public function post(N8nToolRequest $request): N8nToolResponse   { ... } // create
 *       public function patch(N8nToolRequest $request, $id): N8nToolResponse   { ... } // update
 *       public function delete(N8nToolRequest $request, $id): N8nToolResponse  { ... } // delete
 *   }
 *
 * ---
 *
 * HTTP method → handler method mapping:
 *   GET    /n8n/tools/{name}         → get(request)
 *   GET    /n8n/tools/{name}/{id}    → getById(request, id)
 *   POST   /n8n/tools/{name}         → post(request)
 *   PUT    /n8n/tools/{name}/{id}    → put(request, id)
 *   PATCH  /n8n/tools/{name}/{id}    → patch(request, id)
 *   DELETE /n8n/tools/{name}/{id}    → delete(request, id)
 */
abstract class N8nToolHandler
{
    // Subclasses: use #[\Override] attribute on methods you implement
    // to get a compile-time error if the method signature changes.
    // PHP 8.5: #[\Override] now works on properties too.

    /**
     * GET /n8n/tools/{name}
     * List resources — supports filters, search, sorting, pagination.
     *
     * @param N8nToolRequest $request
     * @return N8nToolResponse
     */
    public function get(N8nToolRequest $request): N8nToolResponse
    {
        return $this->methodNotAllowed();
    }

    /**
     * GET /n8n/tools/{name}/{id}
     * Retrieve a single resource by ID.
     *
     * @param N8nToolRequest $request
     * @param string|int $id
     * @return N8nToolResponse
     */
    public function getById(N8nToolRequest $request, string|int $id): N8nToolResponse
    {
        return $this->methodNotAllowed();
    }

    /**
     * POST /n8n/tools/{name}
     * Create a resource or perform an action.
     *
     * @param N8nToolRequest $request
     * @return N8nToolResponse
     */
    public function post(N8nToolRequest $request): N8nToolResponse
    {
        return $this->methodNotAllowed();
    }

    /**
     * PUT /n8n/tools/{name}/{id}
     * Full replacement of a resource.
     *
     * @param N8nToolRequest $request
     * @param string|int $id
     * @return N8nToolResponse
     */
    public function put(N8nToolRequest $request, string|int $id): N8nToolResponse
    {
        return $this->methodNotAllowed();
    }

    /**
     * PATCH /n8n/tools/{name}/{id}
     * Partial update of a resource.
     *
     * @param N8nToolRequest $request
     * @param string|int $id
     * @return N8nToolResponse
     */
    public function patch(N8nToolRequest $request, string|int $id): N8nToolResponse
    {
        return $this->methodNotAllowed();
    }

    /**
     * DELETE /n8n/tools/{name}/{id}
     * Delete a resource.
     *
     * @param N8nToolRequest $request
     * @param string|int $id
     * @return N8nToolResponse
     */
    public function delete(N8nToolRequest $request, string|int $id): N8nToolResponse
    {
        return $this->methodNotAllowed();
    }

    // ── Schema (optional — used in OpenAPI generation) ────────────────────────

    /**
     * JSON Schema for the request body (POST/PATCH/PUT).
     * Override to provide n8n with autocomplete and validation hints.
     */
    public function requestSchema(): array
    {
        return ['type' => 'object'];
    }

    /**
     * JSON Schema for the response data.
     * Override to document what fields n8n can expect.
     */
    public function responseSchema(): array
    {
        return ['type' => 'object'];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /**
     * Returned automatically for unimplemented methods.
     * Do not call directly.
     */
    protected function methodNotAllowed(): N8nToolResponse
    {
        return N8nToolResponse::error('Method not allowed.', 405);
    }
}
