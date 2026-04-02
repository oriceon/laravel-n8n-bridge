<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Oriceon\N8nBridge\Commands\Queue\QueueWorkCommand;
use Oriceon\N8nBridge\Database\Factories\N8nQueueJobFactory;
use Oriceon\N8nBridge\Database\Factories\N8nWorkflowFactory;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Enums\WebhookAuthType;
use Oriceon\N8nBridge\Models\N8nQueueJob;

covers(QueueWorkCommand::class);

beforeEach(function() {
    $this->workflow = N8nWorkflowFactory::new()->create([
        'webhook_path' => 'invoice-reminder',
        'is_active'    => true,
    ]);
});

describe('n8n:queue:work', function() {
    it('recovers stuck jobs and exits when --recover flag is set', function() {
        // Stuck: Running with expired reservation
        N8nQueueJobFactory::new()->create([
            'workflow_id'    => $this->workflow->id,
            'status'         => QueueJobStatus::Running->value,
            'worker_id'      => 'dead-worker:1',
            'reserved_until' => now()->subMinutes(20),
            'queue_name'     => 'default',
        ]);

        $this->artisan('n8n:queue:work', ['--recover' => true])
            ->expectsOutputToContain('Recovered 1 stuck jobs')
            ->assertSuccessful();
    });

    it('processes a single job and exits when --once flag is used', function() {
        Http::fake(['*/webhook*/*' => Http::response(['executionId' => 123], 200)]);

        $job = N8nQueueJobFactory::new()->pending()->create([
            'workflow_id' => $this->workflow->id,
            'queue_name'  => 'default',
        ]);

        $this->artisan('n8n:queue:work', ['--once' => true, '--queue' => 'default'])
            ->expectsOutputToContain('Starting n8n queue worker')
            ->assertSuccessful();

        expect(N8nQueueJob::find($job->id)->status)->not->toBe(QueueJobStatus::Pending);
    });

    it('outputs the queue and sleep settings on startup with --recover', function() {
        $this->artisan('n8n:queue:work', ['--recover' => true, '--queue' => 'high'])
            ->expectsOutputToContain('Recovered')
            ->assertSuccessful();
    });

    // ── Outbound auth per workflow ─────────────────────────────────────────────

    it('sends X-N8N-Workflow-Key header when workflow uses header_token auth', function() {
        Http::fake(['*/webhook*/*' => Http::response(['executionId' => 123], 200)]);

        $workflow = N8nWorkflowFactory::new()->create([
            'webhook_path' => 'auth-header-token',
            'auth_type'    => WebhookAuthType::HeaderToken,
            'auth_key'     => 'my-workflow-token',
            'is_active'    => true,
        ]);

        N8nQueueJobFactory::new()->pending()->create([
            'workflow_id' => $workflow->id,
            'queue_name'  => 'auth-test',
        ]);

        $this->artisan('n8n:queue:work', ['--once' => true, '--queue' => 'auth-test'])
            ->assertSuccessful();

        Http::assertSent(function(Request $request) {
            return $request->hasHeader('X-N8N-Workflow-Key') &&
                $request->header('X-N8N-Workflow-Key')[0] === 'my-workflow-token';
        });
    });

    it('sends Authorization Bearer header when workflow uses bearer auth', function() {
        Http::fake(['*/webhook*/*' => Http::response(['executionId' => 123], 200)]);

        $workflow = N8nWorkflowFactory::new()->create([
            'webhook_path' => 'auth-bearer',
            'auth_type'    => WebhookAuthType::Bearer,
            'auth_key'     => 'bearer-queue-token',
            'is_active'    => true,
        ]);

        N8nQueueJobFactory::new()->pending()->create([
            'workflow_id' => $workflow->id,
            'queue_name'  => 'bearer-test',
        ]);

        $this->artisan('n8n:queue:work', ['--once' => true, '--queue' => 'bearer-test'])
            ->assertSuccessful();

        Http::assertSent(function(Request $request) {
            return $request->hasHeader('Authorization') &&
                $request->header('Authorization')[0] === 'Bearer bearer-queue-token';
        });
    });

    it('sends HMAC signature headers when workflow uses hmac_sha256 auth', function() {
        Http::fake(['*/webhook*/*' => Http::response(['executionId' => 123], 200)]);

        $workflow = N8nWorkflowFactory::new()->create([
            'webhook_path' => 'auth-hmac',
            'auth_type'    => WebhookAuthType::HmacSha256,
            'auth_key'     => 'hmac-signing-key',
            'is_active'    => true,
        ]);

        N8nQueueJobFactory::new()->pending()->create([
            'workflow_id' => $workflow->id,
            'queue_name'  => 'hmac-test',
        ]);

        $this->artisan('n8n:queue:work', ['--once' => true, '--queue' => 'hmac-test'])
            ->assertSuccessful();

        Http::assertSent(function(Request $request) {
            return $request->hasHeader('X-N8N-Timestamp') &&
                $request->hasHeader('X-N8N-Signature') &&
                str_starts_with($request->header('X-N8N-Signature')[0], 'sha256=');
        });
    });

    it('sends no auth headers when workflow uses none auth type', function() {
        Http::fake(['*/webhook*/*' => Http::response(['executionId' => 123], 200)]);

        $workflow = N8nWorkflowFactory::new()->create([
            'webhook_path' => 'auth-none',
            'auth_type'    => WebhookAuthType::None,
            'auth_key'     => null,
            'is_active'    => true,
        ]);

        N8nQueueJobFactory::new()->pending()->create([
            'workflow_id' => $workflow->id,
            'queue_name'  => 'none-test',
        ]);

        $this->artisan('n8n:queue:work', ['--once' => true, '--queue' => 'none-test'])
            ->assertSuccessful();

        Http::assertSent(static function(Request $request) {
            return ! $request->hasHeader('X-N8N-Workflow-Key') &&
                ! $request->hasHeader('Authorization') &&
                ! $request->hasHeader('X-N8N-Signature');
        });
    });
});
