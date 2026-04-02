<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Models\N8nTool;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Register a new /n8n/tools/{name} tool in the database.
 *
 * Usage:
 *   php artisan n8n:tool:create invoices \
 *       --handler="App\N8n\Tools\InvoicesTool" \
 *       --methods=GET,POST \
 *       --label="Invoices" \
 *       --category=billing
 */
#[AsCommand(name: 'n8n:tool:create')]
#[Signature('n8n:tool:create
        {name                  : URL slug — e.g. "invoices" → /n8n/tools/invoices}
        {--handler=            : FQCN of the N8nToolHandler subclass (required)}
        {--label=              : Human-readable label for the schema}
        {--description=        : Short description}
        {--category=           : Category group in OpenAPI schema}
        {--methods=POST        : Allowed HTTP methods, comma-separated (e.g. GET,POST or GET)}
        {--rate-limit=120      : Max requests per minute (0 = unlimited)}')]
#[Description('Register a new /n8n/tools/{name} tool')]
final class ToolCreateCommand extends Command
{
    public function handle(): int
    {
        $name    = (string) $this->argument('name');
        $handler = (string) $this->option('handler');

        if ( ! $handler) {
            $this->error('--handler is required. Example: --handler="App\N8n\Tools\InvoicesTool"');

            return self::FAILURE;
        }

        if ( ! class_exists($handler)) {
            $this->warn("Handler [{$handler}] not found — ensure it exists before n8n calls it.");
        }

        if (N8nTool::where('name', $name)->exists()) {
            $this->error("A tool named [{$name}] already exists.");

            return self::FAILURE;
        }

        $methods = array_map('strtoupper', array_map('trim', explode(',', $this->option('methods'))));

        N8nTool::create([
            'name'            => $name,
            'label'           => $this->option('label') ?: ucwords(str_replace(['-', '_'], ' ', $name)),
            'description'     => $this->option('description'),
            'category'        => $this->option('category'),
            'handler_class'   => $handler,
            'allowed_methods' => $methods,
            'rate_limit'      => (int) $this->option('rate-limit'),
            'is_active'       => true,
        ]);

        $this->info('✅ Tool created!');
        $this->newLine();
        $this->line('  🌐 Methods:  ' . implode(', ', $methods));

        foreach ($methods as $m) {
            $route = in_array($m, ['GET', 'DELETE']) ? "/n8n/tools/{$name}        and /n8n/tools/{$name}/{id}" : "/n8n/tools/{$name}";
            $this->line("  {$m} {$route}");
        }
        $this->newLine();

        $globalKey = config('n8n-bridge.tools.webhook_required', false);

        $this->line('  ℹ️  Attach a credential to require authentication:');
        $this->line("     php artisan n8n:credential:attach {credential-id} --tool={$name}");

        return self::SUCCESS;
    }
}
