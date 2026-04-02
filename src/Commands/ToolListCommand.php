<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Oriceon\N8nBridge\Models\N8nTool;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * List all registered tools.
 *
 *   php artisan n8n:tool:list
 */
#[AsCommand(name: 'n8n:tool:list')]
#[Signature('n8n:tool:list')]
#[Description('List all registered /n8n/tools/* tools')]
final class ToolListCommand extends Command
{
    public function handle(): int
    {
        $tools = N8nTool::with('credential')->orderBy('category')->orderBy('name')->get();

        if ($tools->isEmpty()) {
            $this->info('No tools registered.');
            $this->line('  Run: php artisan n8n:tool:create {name} --handler="App\N8n\Tools\YourTool"');

            return self::SUCCESS;
        }

        $this->table(
            ['Name', 'Label', 'Category', 'Methods', 'Handler', 'Rate', 'Credential', 'Active'],
            $tools->map(fn(N8nTool $t) => [
                $t->name,
                $t->label,
                $t->category ?? '-',
                $t->allowed_methods ? implode(',', $t->allowed_methods) : 'POST',
                class_basename($t->handler_class),
                $t->rate_limit > 0 ? "{$t->rate_limit}/min" : '∞',
                $t->credential ? substr($t->credential->name, 0, 15) : '🔓 open',
                $t->is_active ? '✅' : '❌',
            ])
        );

        return self::SUCCESS;
    }
}
