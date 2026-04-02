<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Http;
use Oriceon\N8nBridge\Client\N8nApiClient;
use Oriceon\N8nBridge\Enums\DeliveryStatus;
use Oriceon\N8nBridge\Facades\N8nBridge;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nWorkflow;
use Oriceon\N8nBridge\N8nBridgeManager;
use Oriceon\N8nBridge\Queue\QueueManager;
use Oriceon\N8nBridge\Stats\StatsManager;

covers(N8nBridgeManager::class);

beforeEach(function() {
    $this->manager  = app(N8nBridgeManager::class);
    $this->workflow = N8nWorkflow::factory()->create([
        'name'         => 'order-shipped',
        'webhook_path' => 'order-shipped',
        'is_active'    => true,
        'n8n_instance' => 'default',
    ]);
});

// ── trigger() ─────────────────────────────────────────────────────────────────

describe('N8nBridgeManager::trigger()', function() {
    it('triggers workflow by model and returns a delivery', function() {
        Http::fake(['*/webhook*/*' => Http::response(['executionId' => 123], 200)]);

        $delivery = $this->manager->trigger($this->workflow, ['order_id' => 1], async: false);

        expect($delivery)->toBeInstanceOf(N8nDelivery::class)
            ->and($delivery->status)->toBe(DeliveryStatus::Done);
    });

    it('triggers workflow by name string', function() {
        Http::fake(['*/webhook*/*' => Http::response(['executionId' => 456], 200)]);

        $delivery = $this->manager->trigger('order-shipped', ['order_id' => 2], async: false);

        expect($delivery->workflow_id)->toBe($this->workflow->id);
    });

    it('throws ModelNotFoundException for unknown workflow name', function() {
        expect(fn() => $this->manager->trigger('nonexistent-workflow', []))
            ->toThrow(ModelNotFoundException::class);
    });
});

// ── client() ─────────────────────────────────────────────────────────────────

describe('N8nBridgeManager::client()', function() {
    it('returns an N8nApiClient for the default instance', function() {
        $client = $this->manager->client('default');

        expect($client)->toBeInstanceOf(N8nApiClient::class);
    });

    it('caches the client — same instance returned twice', function() {
        $a = $this->manager->client('default');
        $b = $this->manager->client('default');

        expect($a)->toBe($b);
    });

    it('throws for unconfigured instance', function() {
        expect(fn() => $this->manager->client('nonexistent'))
            ->toThrow(RuntimeException::class, 'nonexistent');
    });
});

// ── stats() ───────────────────────────────────────────────────────────────────

it('stats() returns a StatsManager instance', function() {
    expect($this->manager->stats())->toBeInstanceOf(StatsManager::class);
});

// ── queue() ───────────────────────────────────────────────────────────────────

it('queue() returns a QueueManager instance', function() {
    expect($this->manager->queue())->toBeInstanceOf(QueueManager::class);
});

// ── health() ─────────────────────────────────────────────────────────────────

describe('N8nBridgeManager::health()', function() {
    it('returns true when n8n instance is healthy', function() {
        Http::fake(['*/healthz' => Http::response('', 200)]);

        expect($this->manager->health('default'))->toBeTrue();
    });

    it('returns false when n8n instance is unhealthy', function() {
        Http::fake(['*/healthz' => Http::response('', 503)]);

        expect($this->manager->health('default'))->toBeFalse();
    });
});

// ── syncWorkflow() ────────────────────────────────────────────────────────────

describe('N8nBridgeManager::syncWorkflow()', function() {
    it('creates a workflow from n8n API response', function() {
        $n8nId = 'n8n-wf-123';
        Http::fake([
            '*/api/v1/workflows/' . $n8nId => Http::response([
                'id'     => $n8nId,
                'name'   => 'My Workflow',
                'active' => true,
                'tags'   => [['name' => 'billing']],
                'nodes'  => [['type' => 'start']],
            ], 200),
        ]);

        $wf = $this->manager->syncWorkflow($n8nId, 'default');

        expect($wf)->toBeInstanceOf(N8nWorkflow::class)
            ->and($wf->n8n_id)->toBe($n8nId)
            ->and($wf->name)->toBe('My Workflow')
            ->and($wf->is_active)->toBeTrue()
            ->and($wf->tags)->toBe(['billing']);
    });

    it('updates existing workflow on re-sync', function() {
        Http::fake([
            '*/api/v1/workflows/wf-001' => Http::response([
                'id'   => 'wf-001', 'name' => 'Updated Name', 'active' => false,
                'tags' => [], 'nodes' => [],
            ], 200),
        ]);

        $first  = $this->manager->syncWorkflow('wf-001', 'default');
        $second = $this->manager->syncWorkflow('wf-001', 'default');

        expect($first->id)->toBe($second->id)
            ->and($second->name)->toBe('Updated Name')
            ->and($second->is_active)->toBeFalse();
    });
});

// ── Facade ────────────────────────────────────────────────────────────────────

it('N8nBridge facade resolves to N8nBridgeManager', function() {
    expect(N8nBridge::getFacadeRoot())->toBeInstanceOf(N8nBridgeManager::class);
});
