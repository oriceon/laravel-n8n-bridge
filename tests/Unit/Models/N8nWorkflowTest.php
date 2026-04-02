<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\WebhookMode;
use Oriceon\N8nBridge\Models\N8nWorkflow;

covers(N8nWorkflow::class);

// ── Scopes ────────────────────────────────────────────────────────────────────

describe('N8nWorkflow scopes', function() {
    it('active() returns only active workflows', function() {
        N8nWorkflow::factory()->create(['is_active' => true]);
        N8nWorkflow::factory()->create(['is_active' => false]);

        expect(N8nWorkflow::active()->count())->toBe(1);
    });

    it('inactive() returns only inactive workflows', function() {
        N8nWorkflow::factory()->create(['is_active' => true]);
        N8nWorkflow::factory()->inactive()->create();

        expect(N8nWorkflow::inactive()->count())->toBe(1);
    });

    it('forInstance() filters by n8n instance', function() {
        N8nWorkflow::factory()->create(['n8n_instance' => 'default']);
        N8nWorkflow::factory()->create(['n8n_instance' => 'staging']);

        expect(N8nWorkflow::forInstance('staging')->count())->toBe(1);
    });

    it('synced() returns only workflows with n8n_id', function() {
        N8nWorkflow::factory()->synced()->create();
        N8nWorkflow::factory()->create(['n8n_id' => null]);

        expect(N8nWorkflow::synced()->count())->toBe(1);
    });

    it('withTag() filters by JSON tag', function() {
        N8nWorkflow::factory()->create(['tags' => ['billing', 'invoices']]);
        N8nWorkflow::factory()->create(['tags' => ['crm']]);

        expect(N8nWorkflow::withTag('billing')->count())->toBe(1);
    });
});

// ── Helpers ───────────────────────────────────────────────────────────────────

describe('N8nWorkflow helpers', function() {
    it('isSynced() returns true when n8n_id is set', function() {
        $wf = N8nWorkflow::factory()->synced()->create();
        expect($wf->isSynced())->toBeTrue();
    });

    it('isSynced() returns false without n8n_id', function() {
        $wf = N8nWorkflow::factory()->create(['n8n_id' => null]);
        expect($wf->isSynced())->toBeFalse();
    });

    it('hasEstimatedDuration() returns false without estimate', function() {
        $wf = N8nWorkflow::factory()->create(['estimated_duration_ms' => null]);
        expect($wf->hasEstimatedDuration())->toBeFalse();
    });

    it('hasEstimatedDuration() returns true with positive estimate', function() {
        $wf = N8nWorkflow::factory()->create(['estimated_duration_ms' => 3000]);
        expect($wf->hasEstimatedDuration())->toBeTrue();
    });

    it('hasEstimatedDuration() returns false for zero', function() {
        $wf = N8nWorkflow::factory()->create(['estimated_duration_ms' => 0]);
        expect($wf->hasEstimatedDuration())->toBeFalse();
    });

    it('estimatedDurationLabel() formats seconds', function() {
        $wf = N8nWorkflow::factory()->create(['estimated_duration_ms' => 2300]);
        expect($wf->estimatedDurationLabel())->toBe('~2.3s');
    });

    it('estimatedDurationLabel() formats minutes', function() {
        $wf = N8nWorkflow::factory()->create(['estimated_duration_ms' => 90_000]);
        expect($wf->estimatedDurationLabel())->toBe('~1.5m');
    });

    it('estimatedDurationLabel() returns null without estimate', function() {
        $wf = N8nWorkflow::factory()->create(['estimated_duration_ms' => null]);
        expect($wf->estimatedDurationLabel())->toBeNull();
    });
});

// ── resolveWebhookUrl ─────────────────────────────────────────────────────────

describe('resolveWebhookUrl()', function() {
    beforeEach(function() {
        config([
            'n8n-bridge.instances.default.webhook_base_url'      => 'https://n8n.example.com/webhook',
            'n8n-bridge.instances.default.webhook_test_base_url' => null,
        ]);
    });

    it('returns production URL when mode is Production', function() {
        $wf = N8nWorkflow::factory()->create([
            'webhook_path' => 'abc-123',
            'webhook_mode' => WebhookMode::Production,
        ]);

        expect($wf->resolveWebhookUrl())
            ->toBe('https://n8n.example.com/webhook/abc-123');
    });

    it('returns test URL when mode is Test', function() {
        $wf = N8nWorkflow::factory()->create([
            'webhook_path' => 'abc-123',
            'webhook_mode' => WebhookMode::Test,
        ]);

        expect($wf->resolveWebhookUrl())
            ->toBe('https://n8n.example.com/webhook-test/abc-123');
    });

    it('auto mode uses test URL in non-production environment', function() {
        app()->detectEnvironment(fn() => 'local');

        $wf = N8nWorkflow::factory()->create([
            'webhook_path' => 'my-flow',
            'webhook_mode' => WebhookMode::Auto,
        ]);

        expect($wf->resolveWebhookUrl())
            ->toBe('https://n8n.example.com/webhook-test/my-flow');
    });

    it('auto mode uses production URL in production environment', function() {
        app()->detectEnvironment(fn() => 'production');

        $wf = N8nWorkflow::factory()->create([
            'webhook_path' => 'my-flow',
            'webhook_mode' => WebhookMode::Auto,
        ]);

        expect($wf->resolveWebhookUrl())
            ->toBe('https://n8n.example.com/webhook/my-flow');

        app()->detectEnvironment(fn() => 'testing'); // restore
    });

    it('respects explicit webhook_test_base_url from config', function() {
        config(['n8n-bridge.instances.default.webhook_test_base_url' => 'https://n8n.example.com/webhook-test']);

        $wf = N8nWorkflow::factory()->create([
            'webhook_path' => 'abc-123',
            'webhook_mode' => WebhookMode::Test,
        ]);

        expect($wf->resolveWebhookUrl())
            ->toBe('https://n8n.example.com/webhook-test/abc-123');
    });

    it('strips leading slashes from webhook_path', function() {
        $wf = N8nWorkflow::factory()->create([
            'webhook_path' => '/leading-slash',
            'webhook_mode' => WebhookMode::Production,
        ]);

        expect($wf->resolveWebhookUrl())
            ->toBe('https://n8n.example.com/webhook/leading-slash');
    });

    it('default webhook_mode is auto', function() {
        $wf = N8nWorkflow::factory()->make();
        expect($wf->webhook_mode)->toBe(WebhookMode::Auto);
    });
});

// ── Soft deletes ──────────────────────────────────────────────────────────────

it('supports soft deletes', function() {
    $wf = N8nWorkflow::factory()->create();
    $id = $wf->id;

    $wf->delete();

    expect(N8nWorkflow::find($id))->toBeNull()
        ->and(N8nWorkflow::withTrashed()->find($id))->not->toBeNull();
});
