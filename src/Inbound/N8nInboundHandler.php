<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Inbound;

use Oriceon\N8nBridge\DTOs\N8nPayload;

/**
 * Abstract base for all inbound n8n webhook handlers.
 *
 * Extend this class and implement handle().
 * The pipeline calls handle() after all security checks pass.
 *
 * Usage:
 *
 *   class InvoicePaidHandler extends N8nInboundHandler
 *   {
 *       public function handle(N8nPayload $payload): void
 *       {
 *           $invoice = Invoice::findOrFail($payload->required('invoice_id'));
 *           $invoice->markAsPaid($payload->getFloat('amount'));
 *           event(new InvoicePaid($invoice));
 *       }
 *   }
 */
abstract class N8nInboundHandler
{
    /**
     * Process the inbound payload.
     * Throw any exception to trigger retry/DLQ logic.
     *
     * @param N8nPayload $payload
     */
    abstract public function handle(N8nPayload $payload): void;

    /**
     * Optional: validate the payload before handle() is called.
     * Return an array of validation rules (Laravel validation syntax).
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    /**
     * Optional: custom validation messages.
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [];
    }
}
