<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Models\N8nWorkflow;
use Oriceon\N8nBridge\Outbound\OutboundRateLimiter;

covers(OutboundRateLimiter::class);

beforeEach(function() {
    $this->limiter  = app(OutboundRateLimiter::class);
    $this->workflow = N8nWorkflow::factory()->create([
        'webhook_path' => 'rate-limit-test',
        'rate_limit'   => null, // use global config
    ]);
    // Ensure a clean slate before each test
    $this->limiter->reset($this->workflow);
});

describe('OutboundRateLimiter', function() {

    // ── No limit ─────────────────────────────────────────────────────────────

    it('returns null (allowed) when global limit is 0 (unlimited)', function() {
        config(['n8n-bridge.outbound.rate_limit' => 0]);

        expect($this->limiter->check($this->workflow))->toBeNull();
    });

    it('returns null (allowed) when per-workflow limit is 0', function() {
        $this->workflow->update(['rate_limit' => 0]);

        expect($this->limiter->check($this->workflow->fresh()))->toBeNull();
    });

    // ── Within limit ─────────────────────────────────────────────────────────

    it('allows the first request when limit is set', function() {
        config(['n8n-bridge.outbound.rate_limit' => 5]);

        expect($this->limiter->check($this->workflow))->toBeNull();
    });

    it('allows requests up to the limit', function() {
        config(['n8n-bridge.outbound.rate_limit' => 3]);

        // Three calls should all be allowed
        expect($this->limiter->check($this->workflow))->toBeNull()
            ->and($this->limiter->check($this->workflow))->toBeNull()
            ->and($this->limiter->check($this->workflow))->toBeNull();
    });

    // ── Limit exceeded ────────────────────────────────────────────────────────

    it('returns wait seconds when limit exceeded', function() {
        config(['n8n-bridge.outbound.rate_limit' => 2]);

        $this->limiter->check($this->workflow);
        $this->limiter->check($this->workflow);

        $wait = $this->limiter->check($this->workflow);

        expect($wait)->toBeInt()->toBeGreaterThanOrEqual(1);
    });

    // ── Per-workflow override ─────────────────────────────────────────────────

    it('per-workflow limit overrides global config', function() {
        config(['n8n-bridge.outbound.rate_limit' => 100]); // global = 100 req/min

        $this->workflow->update(['rate_limit' => 1]); // per-workflow = 1 req/min
        $wf = $this->workflow->fresh();

        expect($this->limiter->check($wf))->toBeNull(); // first allowed

        $wait = $this->limiter->check($wf);

        expect($wait)->toBeInt()->toBeGreaterThanOrEqual(1);
    });

    it('per-workflow 0 overrides non-zero global and skips limiting', function() {
        config(['n8n-bridge.outbound.rate_limit' => 1]); // global = 1 req/min

        $this->workflow->update(['rate_limit' => 0]);
        $wf = $this->workflow->fresh();

        // Even after many calls, should remain allowed
        expect($this->limiter->check($wf))->toBeNull()
            ->and($this->limiter->check($wf))->toBeNull()
            ->and($this->limiter->check($wf))->toBeNull();
    });

    // ── Buckets are per workflow ──────────────────────────────────────────────

    it('different workflows have independent rate-limit buckets', function() {
        config(['n8n-bridge.outbound.rate_limit' => 1]);

        $wf2 = N8nWorkflow::factory()->create(['webhook_path' => 'other-workflow']);
        $this->limiter->reset($wf2);

        // Exhaust wf1
        $this->limiter->check($this->workflow);
        expect($this->limiter->check($this->workflow))->toBeInt(); // limited

        // wf2 bucket is independent — still allowed
        expect($this->limiter->check($wf2))->toBeNull();
    });

    // ── Reset ─────────────────────────────────────────────────────────────────

    it('reset clears the bucket and allows requests again', function() {
        config(['n8n-bridge.outbound.rate_limit' => 1]);

        $this->limiter->check($this->workflow);
        expect($this->limiter->check($this->workflow))->toBeInt(); // limited

        $this->limiter->reset($this->workflow);

        expect($this->limiter->check($this->workflow))->toBeNull(); // allowed again
    });
});
