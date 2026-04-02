<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Oriceon\N8nBridge\Concerns\TriggersN8nOnEvents;
use Oriceon\N8nBridge\Models\N8nWorkflow;
use Tests\Stubs\OrderModel;
use Tests\Stubs\OrderWithPayload;

covers(TriggersN8nOnEvents::class);

// ── Inline stub model ─────────────────────────────────────────────────────────

beforeAll(function() {
    // Create a stub Eloquent model that uses the trait
    if ( ! class_exists('Tests\Stubs\OrderModel')) {
        eval('
            namespace Tests\Stubs;

            use Illuminate\Database\Eloquent\Model;
            use Oriceon\N8nBridge\Concerns\HasDynamicTable;
            use Oriceon\N8nBridge\Concerns\HasPublicUuid;
            use Oriceon\N8nBridge\Concerns\TriggersN8nOnEvents;

            class OrderModel extends Model
            {
                use HasDynamicTable, HasPublicUuid, TriggersN8nOnEvents;

                protected $table = "orders_test";

                protected $fillable = ["name", "status", "amount"];

                public array $n8nTriggers = [
                    "created" => "order-created",
                    "updated" => [
                        "workflow"  => "order-status-changed",
                        "only_when" => ["status"],
                    ],
                    "deleted" => "order-deleted",
                ];

                protected function getTableBaseName(): string { return "orders_test"; }
            }
        ');
    }
});

beforeEach(function() {
    Http::fake(['*/webhook*/*' => Http::response(['ok' => true], 200)]);

    // Create the stub table in SQLite — HasDynamicTable adds "n8n__" prefix
    Schema::create('n8n__orders_test', function($table) {
        $table->id();
        $table->string('uuid')->unique();
        $table->string('name')->nullable();
        $table->string('status')->nullable();
        $table->integer('amount')->nullable();
        $table->timestamps();
    });
});

afterEach(function() {
    Schema::dropIfExists('n8n__orders_test');
});

// ── created trigger ───────────────────────────────────────────────────────────

it('triggers outbound on created event when workflow exists', function() {
    N8nWorkflow::factory()->create([
        'name'         => 'order-created',
        'webhook_path' => 'order-created',
        'is_active'    => true,
    ]);

    $order = OrderModel::create(['name' => 'Test Order', 'status' => 'new', 'amount' => 100]);

    Http::assertSent(static fn($req) => str_contains($req->url(), 'order-created'));
});

it('does not fire when workflow is not found', function() {
    // No workflow with name "order-created"
    $order = OrderModel::create(['name' => 'No Workflow', 'status' => 'new', 'amount' => 50]);

    Http::assertNothingSent();
});

// ── updated trigger with only_when ────────────────────────────────────────────

it('fires on updated when a watched field changes', function() {
    N8nWorkflow::factory()->create([
        'name'         => 'order-status-changed',
        'webhook_path' => 'order-status-changed',
        'is_active'    => true,
    ]);

    $order = OrderModel::create(['name' => 'Order', 'status' => 'new', 'amount' => 100]);

    Http::fake(['*/webhook*/*' => Http::response(['ok' => true], 200)]);

    $order->update(['status' => 'paid']); // watched field

    Http::assertSent(static fn($req) => str_contains($req->url(), 'order-status-changed'));
});

it('does not fire on updated when non-watched field changes', function() {
    N8nWorkflow::factory()->create([
        'name'         => 'order-status-changed',
        'webhook_path' => 'order-status-changed',
        'is_active'    => true,
    ]);

    $order = OrderModel::create(['name' => 'Order', 'status' => 'new', 'amount' => 100]);

    Http::fake(['*/webhook*/*' => Http::response(['ok' => true], 200)]);

    $order->update(['amount' => 200]); // NOT a watched field

    Http::assertNothingSent();
});

// ── Custom toN8nPayload() ─────────────────────────────────────────────────────

it('uses toN8nPayload() when defined on the model', function() {
    if ( ! class_exists('Tests\Stubs\OrderWithPayload')) {
        eval('
            namespace Tests\Stubs;

            use Illuminate\Database\Eloquent\Model;
            use Oriceon\N8nBridge\Concerns\HasDynamicTable;
            use Oriceon\N8nBridge\Concerns\HasPublicUuid;
            use Oriceon\N8nBridge\Concerns\TriggersN8nOnEvents;

            class OrderWithPayload extends Model
            {
                use HasDynamicTable, HasPublicUuid, TriggersN8nOnEvents;
                protected $table = "orders_test";
                protected $fillable = ["name", "status", "amount"];
                public array $n8nTriggers = ["created" => "order-created"];
                public function toN8nPayload(string $event): array {
                    return ["custom" => "yes", "id" => $this->id];
                }
                protected function getTableBaseName(): string { return "orders_test"; }
            }
        ');
    }

    N8nWorkflow::factory()->create([
        'name'         => 'order-created',
        'webhook_path' => 'order-created',
        'is_active'    => true,
    ]);

    Http::fake(['*/webhook*/*' => Http::response(['ok' => true], 200)]);

    OrderWithPayload::create(['name' => 'Custom', 'status' => 'new', 'amount' => 1]);

    Http::assertSent(static fn($req) => $req->data() === ['custom' => 'yes', 'id' => 1] || isset($req->data()['custom']));
});
