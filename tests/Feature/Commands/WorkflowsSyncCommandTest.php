<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Oriceon\N8nBridge\Commands\WorkflowsSyncCommand;
use Oriceon\N8nBridge\Models\N8nWorkflow;

covers(WorkflowsSyncCommand::class);

describe('n8n:workflows:sync', function() {
    it('syncs workflows from the n8n API into the local database', function() {
        Http::fake([
            '*/api/v1/workflows*' => Http::response([
                'data' => [
                    ['id' => 'wf-001', 'name' => 'Invoice Created', 'active' => true,  'tags' => [], 'meta' => [], 'createdAt' => '2026-02-19T13:54:18.004Z', 'updatedAt' => '2026-03-27T10:14:43.405Z'],
                    ['id' => 'wf-002', 'name' => 'Order Shipped',   'active' => false, 'tags' => [['name' => 'crm']], 'meta' => ['templateCredsSetupCompleted' => true], 'createdAt' => '2026-02-19T13:54:18.004Z', 'updatedAt' => '2026-03-27T10:14:43.405Z'],
                ],
            ], 200),
        ]);

        $this->artisan('n8n:workflows:sync')
            ->expectsOutputToContain('Synced 2 workflows')
            ->assertSuccessful();

        expect(N8nWorkflow::where('n8n_id', 'wf-001')->exists())->toBeTrue()
            ->and(N8nWorkflow::where('n8n_id', 'wf-002')->exists())->toBeTrue();

        $wf2 = N8nWorkflow::where('n8n_id', 'wf-002')->first();
        expect($wf2->tags)->toBe(['crm'])
            ->and($wf2->is_active)->toBeFalse();
    });

    it('reports zero synced when n8n returns empty data', function() {
        Http::fake([
            '*/api/v1/workflows*' => Http::response(['data' => []], 200),
        ]);

        $this->artisan('n8n:workflows:sync')
            ->expectsOutputToContain('Synced 0 workflows')
            ->assertSuccessful();
    });

    it('updates existing workflow records on re-sync', function() {
        Http::fake([
            '*/api/v1/workflows*' => Http::response([
                'data' => [
                    ['id' => 'wf-001', 'name' => 'Updated Name', 'active' => true, 'tags' => [], 'meta' => [], 'createdAt' => '2026-02-19T13:54:18.004Z', 'updatedAt' => '2026-03-27T10:14:43.405Z'],
                ],
            ], 200),
        ]);

        N8nWorkflow::create([
            'uuid'         => (string) Str::uuid(),
            'n8n_id'       => 'wf-001',
            'n8n_instance' => 'default',
            'name'         => 'Old Name',
            'is_active'    => false,
            'tags'         => [],
        ]);

        $this->artisan('n8n:workflows:sync')->assertSuccessful();

        $wf = N8nWorkflow::where('n8n_id', 'wf-001')->first();
        expect($wf->name)->toBe('Updated Name')
            ->and($wf->is_active)->toBeTrue();
    });
});
