<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Oriceon\N8nBridge\Database\Factories\N8nQueueJobFactory;
use Oriceon\N8nBridge\Database\Factories\N8nWorkflowFactory;
use Oriceon\N8nBridge\Enums\CheckpointStatus;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Events\N8nQueueJobProgressUpdatedEvent;
use Oriceon\N8nBridge\Models\N8nCredential;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Oriceon\N8nBridge\Models\N8nQueueCheckpoint;
use Oriceon\N8nBridge\Queue\Http\QueueProgressController;

covers(QueueProgressController::class);

beforeEach(function() {
    config(['n8n-bridge.queue.delete_checkpoints_on_success' => true]);

    // Create a credential with a key
    $this->credential = N8nCredential::create(['name' => 'Test', 'is_active' => true]);
    [$this->apiKey]   = $this->credential->generateKey();

    // Create a workflow + inbound endpoint attached to a credential
    $this->workflow = N8nWorkflowFactory::new()->create();

    N8nEndpoint::create([
        'slug'          => 'progress-test',
        'handler_class' => 'App\\N8n\\TestHandler',
        'credential_id' => $this->credential->id,
    ]);

    $this->job = N8nQueueJobFactory::new()->create([
        'workflow_id' => $this->workflow->id,
        'status'      => QueueJobStatus::Running->value,
        'started_at'  => now(),
    ]);
});

// ── POST /n8n/queue/progress/{jobId} ─────────────────────────────────────────

it('stores a checkpoint and returns 201', function() {
    $this->postJson(
        "/n8n/queue/progress/{$this->job->uuid}",
        ['node'      => 'send_invoice', 'status' => 'completed', 'message' => 'Done'],
        ['X-N8N-Key' => $this->apiKey]
    )->assertStatus(201)
        ->assertJsonStructure(['checkpoint_id', 'sequence', 'job_status']);

    expect(N8nQueueCheckpoint::where('job_id', $this->job->id)->count())->toBe(1);
});

it('fires N8nQueueJobProgressUpdatedEvent event on checkpoint store', function() {
    Event::fake([N8nQueueJobProgressUpdatedEvent::class]);

    $this->postJson(
        "/n8n/queue/progress/{$this->job->uuid}",
        ['node'      => 'enrich_contact', 'status' => 'running'],
        ['X-N8N-Key' => $this->apiKey]
    )->assertStatus(201);

    Event::assertDispatched(
        N8nQueueJobProgressUpdatedEvent::class,
        fn($e) => $e->job->id === $this->job->id &&
        $e->checkpoint->node_name === 'enrich_contact'
    );
});

it('increments sequence number for each checkpoint', function() {
    foreach (['node_a', 'node_b', 'node_c'] as $node) {
        $this->postJson(
            "/n8n/queue/progress/{$this->job->uuid}",
            ['node'      => $node, 'status' => 'completed'],
            ['X-N8N-Key' => $this->apiKey]
        )->assertStatus(201);
    }

    $sequences = N8nQueueCheckpoint::where('job_id', $this->job->id)
        ->orderBy('sequence')->pluck('sequence')->toArray();

    expect($sequences)->toBe([1, 2, 3]);
});

it('marks job Done when __done__ node received', function() {
    $this->postJson(
        "/n8n/queue/progress/{$this->job->uuid}",
        ['node'      => '__done__', 'status' => 'completed', 'message' => 'exec_abc'],
        ['X-N8N-Key' => $this->apiKey]
    )->assertStatus(201);

    expect($this->job->fresh()->status)->toBe(QueueJobStatus::Done);
});

it('marks job Failed when __failed__ node received', function() {
    $this->postJson(
        "/n8n/queue/progress/{$this->job->uuid}",
        ['node'      => '__failed__', 'status' => 'failed', 'error_message' => 'API error'],
        ['X-N8N-Key' => $this->apiKey]
    )->assertStatus(201);

    $status = $this->job->fresh()->status;
    expect($status === QueueJobStatus::Failed || $status === QueueJobStatus::Dead)->toBeTrue();
});

it('returns 401 with missing API key', function() {
    $this->postJson(
        "/n8n/queue/progress/{$this->job->uuid}",
        ['node' => 'test', 'status' => 'running']
    )->assertStatus(401);
});

it('returns 401 with wrong API key', function() {
    $this->postJson(
        "/n8n/queue/progress/{$this->job->uuid}",
        ['node'      => 'test', 'status' => 'running'],
        ['X-N8N-Key' => 'wrong-key']
    )->assertStatus(401);
});

it('returns 404 for unknown job ID', function() {
    $this->postJson(
        '/n8n/queue/progress/00000000-0000-0000-0000-000000000000',
        ['node'      => 'test', 'status' => 'running'],
        ['X-N8N-Key' => $this->apiKey]
    )->assertStatus(404);
});

it('returns 422 for invalid status value', function() {
    $this->postJson(
        "/n8n/queue/progress/{$this->job->uuid}",
        ['node'      => 'test', 'status' => 'invalid'],
        ['X-N8N-Key' => $this->apiKey]
    )->assertStatus(422);
});

it('returns 422 when node is missing', function() {
    $this->postJson(
        "/n8n/queue/progress/{$this->job->uuid}",
        ['status'    => 'completed'],
        ['X-N8N-Key' => $this->apiKey]
    )->assertStatus(422);
});

// ── GET /n8n/queue/progress/{jobId} ──────────────────────────────────────────

it('GET returns job info and timeline', function() {
    N8nQueueCheckpoint::create([
        'job_id' => $this->job->id, 'node_name' => 'node_a',
        'status' => CheckpointStatus::Completed, 'sequence' => 1,
    ]);

    $this->getJson("/n8n/queue/progress/{$this->job->uuid}", ['X-N8N-Key' => $this->apiKey])
        ->assertOk()
        ->assertJsonStructure([
            'job' => ['id', 'workflow', 'status', 'priority', 'attempts'],
            'timeline', 'total_steps', 'completed_steps', 'has_failures', 'progress_percent',
        ]);
});

it('GET returns 404 for unknown job (with valid key)', function() {
    $this->getJson(
        '/n8n/queue/progress/00000000-0000-0000-0000-000000000000',
        ['X-N8N-Key' => $this->apiKey]
    )->assertStatus(404);
});

it('GET returns 401 without key', function() {
    $this->getJson('/n8n/queue/progress/00000000-0000-0000-0000-000000000000')
        ->assertStatus(401);
});

// ── Auth required on all queue progress routes ───────────────────────────────

it('job progress requires valid X-N8N-Key even for jobs without specific endpoint webhook', function() {
    $workflow2 = N8nWorkflowFactory::new()->create();
    $openJob   = N8nQueueJobFactory::new()->create([
        'workflow_id' => $workflow2->id,
        'status'      => QueueJobStatus::Running->value,
    ]);

    // No key → 401 (middleware runs first)
    $this->postJson(
        "/n8n/queue/progress/{$openJob->id}",
        ['node' => 'test', 'status' => 'running']
    )->assertStatus(401);

    // Valid key from any webhook → 201 (no specific endpoint restriction)
    $this->postJson(
        "/n8n/queue/progress/{$openJob->id}",
        ['node'      => 'test', 'status' => 'running'],
        ['X-N8N-Key' => $this->apiKey]
    )->assertStatus(201);
});

// ── Auto-delete checkpoints on success ────────────────────────────────────────

it('auto-deletes checkpoints when job transitions to Done', function() {
    foreach (['node_1', 'node_2'] as $node) {
        $this->postJson(
            "/n8n/queue/progress/{$this->job->uuid}",
            ['node'      => $node, 'status' => 'completed'],
            ['X-N8N-Key' => $this->apiKey]
        );
    }

    expect(N8nQueueCheckpoint::where('job_id', $this->job->id)->count())->toBe(2);

    $this->postJson(
        "/n8n/queue/progress/{$this->job->uuid}",
        ['node'      => '__done__', 'status' => 'completed'],
        ['X-N8N-Key' => $this->apiKey]
    );

    expect(N8nQueueCheckpoint::where('job_id', $this->job->id)->count())->toBe(0);
});
