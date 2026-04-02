<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Commands\ToolCreateCommand;
use Oriceon\N8nBridge\Commands\ToolListCommand;
use Oriceon\N8nBridge\Database\Factories\N8nToolFactory;
use Oriceon\N8nBridge\Models\N8nTool;

covers(ToolCreateCommand::class, ToolListCommand::class);

// ── n8n:tool:create ───────────────────────────────────────────────────────────

describe('n8n:tool:create', function() {
    it('fails when --handler is missing', function() {
        $this->artisan('n8n:tool:create', ['name' => 'invoices'])
            ->expectsOutputToContain('--handler is required')
            ->assertFailed();
    });

    it('fails when a tool with the same name already exists', function() {
        N8nToolFactory::new()->create(['name' => 'invoices']);

        $this->artisan('n8n:tool:create', [
            'name'      => 'invoices',
            '--handler' => 'App\\N8n\\Tools\\InvoicesTool',
        ])
            ->expectsOutputToContain('already exists')
            ->assertFailed();
    });

    it('creates a tool record with defaults', function() {
        $this->artisan('n8n:tool:create', [
            'name'      => 'contacts',
            '--handler' => 'App\\N8n\\Tools\\ContactsTool',
        ])
            ->expectsOutputToContain('Tool created')
            ->assertSuccessful();

        $tool = N8nTool::where('name', 'contacts')->firstOrFail();
        expect($tool->label)->toBe('Contacts')
            ->and($tool->is_active)->toBeTrue()
            ->and($tool->allowed_methods)->toBe(['POST']);
    });

    it('applies custom options', function() {
        $this->artisan('n8n:tool:create', [
            'name'          => 'reports',
            '--handler'     => 'App\\N8n\\Tools\\ReportsTool',
            '--label'       => 'My Reports',
            '--description' => 'Report generation',
            '--category'    => 'analytics',
            '--methods'     => 'GET,POST',
            '--rate-limit'  => '60',
        ])->assertSuccessful();

        $tool = N8nTool::where('name', 'reports')->firstOrFail();
        expect($tool->label)->toBe('My Reports')
            ->and($tool->description)->toBe('Report generation')
            ->and($tool->category)->toBe('analytics')
            ->and($tool->allowed_methods)->toBe(['GET', 'POST'])
            ->and($tool->rate_limit)->toBe(60);
    });
});

// ── n8n:tool:list ─────────────────────────────────────────────────────────────

describe('n8n:tool:list', function() {
    it('shows no tools message when empty', function() {
        $this->artisan('n8n:tool:list')
            ->expectsOutputToContain('No tools registered')
            ->assertSuccessful();
    });

    it('lists registered tools in a table', function() {
        N8nToolFactory::new()->create(['name' => 'invoices',  'label' => 'Invoices',  'category' => 'billing']);
        N8nToolFactory::new()->create(['name' => 'contacts',  'label' => 'Contacts',  'category' => 'crm']);

        $this->artisan('n8n:tool:list')
            ->expectsOutputToContain('invoices')
            ->expectsOutputToContain('contacts')
            ->assertSuccessful();
    });
});
