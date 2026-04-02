<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Oriceon\N8nBridge\Auth\WebhookAuthService;
use Oriceon\N8nBridge\Enums\DeliveryDirection;
use Oriceon\N8nBridge\Enums\DeliveryStatus;
use Oriceon\N8nBridge\Enums\WebhookAuthType;
use Oriceon\N8nBridge\Events\N8nWorkflowTriggeredEvent;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nWorkflow;
use Oriceon\N8nBridge\Outbound\N8nOutboundDispatcher;
use Oriceon\N8nBridge\Outbound\OutboundRateLimiter;

covers(N8nOutboundDispatcher::class);

describe('N8nOutboundDispatcher', function() {
    beforeEach(function() {
        $this->dispatcher = app(N8nOutboundDispatcher::class);
        $this->workflow   = N8nWorkflow::factory()->create([
            'n8n_instance' => 'default',
            'webhook_path' => 'order-shipped',
            'auth_type'    => WebhookAuthType::None,
        ]);
        // Ensure unlimited by default so other tests are unaffected
        config(['n8n-bridge.outbound.rate_limit' => 0]);
        app(OutboundRateLimiter::class)->reset($this->workflow);
    });

    // ── Core behaviour ─────────────────────────────────────────────────────────

    it('creates a delivery record on trigger', function() {
        Http::fake(['*' => Http::response(['executionId' => 123], 200)]);

        $delivery = $this->dispatcher->trigger($this->workflow, ['order_id' => 42], async: false);

        expect(N8nDelivery::where('workflow_id', $this->workflow->id)->count())->toBe(1)
            ->and($delivery->direction)->toBe(DeliveryDirection::Outbound);
    });

    it('marks delivery as done on successful HTTP response', function() {
        Http::fake(['*' => Http::response(['executionId' => 123], 200)]);

        $delivery = $this->dispatcher->trigger($this->workflow, ['order_id' => 1], async: false);

        expect($delivery->fresh()->status)->toBe(DeliveryStatus::Done)
            ->and($delivery->fresh()->http_status)->toBe(200)
            ->and($delivery->fresh()->n8n_execution_id)->toBe(123);
    });

    it('marks delivery as failed on HTTP error', function() {
        Http::fake(['*' => Http::response(['error' => 'workflow not found'], 404)]);

        $delivery = $this->dispatcher->trigger($this->workflow, [], async: false);

        expect($delivery->fresh()->status)->toBe(DeliveryStatus::Failed)
            ->and($delivery->fresh()->http_status)->toBe(404)
            ->and($delivery->fresh()->error_message)->toContain('404');
    });

    it('marks delivery as failed on connection error', function() {
        Http::fake(['*' => fn() => throw new Exception('Connection refused')]);

        $delivery = $this->dispatcher->trigger($this->workflow, [], async: false);

        expect($delivery->fresh()->status)->toBe(DeliveryStatus::Failed)
            ->and($delivery->fresh()->error_message)->toContain('Connection refused');
    });

    it('fires N8nWorkflowTriggeredEvent event on success', function() {
        Http::fake(['*' => Http::response(['executionId' => 123], 200)]);
        Event::fake([N8nWorkflowTriggeredEvent::class]);

        $this->dispatcher->trigger($this->workflow, ['key' => 'value'], async: false);

        Event::assertDispatched(N8nWorkflowTriggeredEvent::class, function($event) {
            return $event->workflow->id === $this->workflow->id &&
                $event->payload === ['key' => 'value'];
        });
    });

    it('sends payload as JSON to correct n8n webhook URL', function() {
        Http::fake(['*' => Http::response([], 200)]);

        $this->dispatcher->trigger($this->workflow, ['foo' => 'bar'], async: false);

        Http::assertSent(function(Request $request) {
            return str_contains($request->url(), 'order-shipped') &&
                str_contains($request->url(), 'webhook') &&
                $request->isJson() &&
                $request->data() === ['foo' => 'bar'];
        });
    });

    it('stores payload in delivery record', function() {
        Http::fake(['*' => Http::response([], 200)]);

        $delivery = $this->dispatcher->trigger(
            $this->workflow,
            ['order_id' => 55, 'amount' => 199.99],
            async: false
        );

        expect($delivery->fresh()->payload)->toBe(['order_id' => 55, 'amount' => 199.99]);
    });

    it('throws when instance is not configured', function() {
        $workflow = N8nWorkflow::factory()->create([
            'n8n_instance' => 'nonexistent',
            'webhook_path' => 'test',
        ]);

        expect(fn() => $this->dispatcher->trigger($workflow, [], async: false))
            ->toThrow(RuntimeException::class, 'nonexistent');
    });

    // ── Auth: none ────────────────────────────────────────────────────────────

    describe('auth type: none', function() {

        it('sends no auth headers when auth type is none', function() {
            Http::fake(['*' => Http::response([], 200)]);

            $this->workflow->update([
                'auth_type' => WebhookAuthType::None,
                'auth_key'  => null,
            ]);

            $this->dispatcher->trigger($this->workflow->fresh(), ['x' => 1], async: false);

            Http::assertSent(function(Request $request) {
                return ! $request->hasHeader('X-N8N-Workflow-Key') &&
                    ! $request->hasHeader('Authorization') &&
                    ! $request->hasHeader('X-N8N-Signature');
            });
        });
    });

    // ── Auth: header_token ────────────────────────────────────────────────────

    describe('auth type: header_token', function() {
        it('sends X-N8N-Workflow-Key header', function() {
            Http::fake(['*' => Http::response([], 200)]);

            $workflow = N8nWorkflow::factory()->create([
                'n8n_instance' => 'default',
                'webhook_path' => 'header-token-test',
                'auth_type'    => WebhookAuthType::HeaderToken,
                'auth_key'     => 'secret-header-token',
            ]);

            $this->dispatcher->trigger($workflow, ['ping' => true], async: false);

            Http::assertSent(function(Request $request) {
                return $request->hasHeader('X-N8N-Workflow-Key') &&
                    $request->header('X-N8N-Workflow-Key')[0] === 'secret-header-token';
            });
        });

        it('does not send auth header when key is null', function() {
            Http::fake(['*' => Http::response([], 200)]);

            $workflow = N8nWorkflow::factory()->create([
                'n8n_instance' => 'default',
                'webhook_path' => 'no-key-test',
                'auth_type'    => WebhookAuthType::HeaderToken,
                'auth_key'     => null,
            ]);

            $this->dispatcher->trigger($workflow, [], async: false);

            Http::assertSent(function(Request $request) {
                return ! $request->hasHeader('X-N8N-Workflow-Key');
            });
        });
    });

    // ── Auth: bearer ──────────────────────────────────────────────────────────

    describe('auth type: bearer', function() {
        it('sends Authorization Bearer header', function() {
            Http::fake(['*' => Http::response([], 200)]);

            $workflow = N8nWorkflow::factory()->create([
                'n8n_instance' => 'default',
                'webhook_path' => 'bearer-test',
                'auth_type'    => WebhookAuthType::Bearer,
                'auth_key'     => 'bearer-token-abc',
            ]);

            $this->dispatcher->trigger($workflow, ['event' => 'created'], async: false);

            Http::assertSent(function(Request $request) {
                return $request->hasHeader('Authorization') &&
                    $request->header('Authorization')[0] === 'Bearer bearer-token-abc';
            });
        });

        it('does not send auth header when key is null', function() {
            Http::fake(['*' => Http::response([], 200)]);

            $workflow = N8nWorkflow::factory()->create([
                'n8n_instance' => 'default',
                'webhook_path' => 'bearer-no-key',
                'auth_type'    => WebhookAuthType::Bearer,
                'auth_key'     => null,
            ]);

            $this->dispatcher->trigger($workflow, [], async: false);

            Http::assertSent(function(Request $request) {
                return ! $request->hasHeader('Authorization');
            });
        });
    });

    // ── Auth: hmac_sha256 ─────────────────────────────────────────────────────

    describe('auth type: hmac_sha256', function() {
        it('sends X-N8N-Timestamp and X-N8N-Signature headers', function() {
            Http::fake(['*' => Http::response([], 200)]);

            $workflow = N8nWorkflow::factory()->create([
                'n8n_instance' => 'default',
                'webhook_path' => 'hmac-test',
                'auth_type'    => WebhookAuthType::HmacSha256,
                'auth_key'     => 'hmac-secret-256',
            ]);

            $before = time();
            $this->dispatcher->trigger($workflow, ['data' => 'payload'], async: false);
            $after = time();

            Http::assertSent(function(Request $request) use ($before, $after) {
                if ( ! $request->hasHeader('X-N8N-Timestamp') || ! $request->hasHeader('X-N8N-Signature')) {
                    return false;
                }

                $ts = (int) $request->header('X-N8N-Timestamp')[0];

                return $ts >= $before &&
                    $ts <= $after &&
                    str_starts_with($request->header('X-N8N-Signature')[0], 'sha256=');
            });
        });

        it('signature is verifiable with the workflow key and sent body', function() {
            Http::fake(['*' => Http::response([], 200)]);

            $key      = WebhookAuthService::generateKey();
            $payload  = ['order_id' => 99, 'amount' => 299.99];
            $workflow = N8nWorkflow::factory()->create([
                'n8n_instance' => 'default',
                'webhook_path' => 'hmac-verify',
                'auth_type'    => WebhookAuthType::HmacSha256,
                'auth_key'     => $key,
            ]);

            $this->dispatcher->trigger($workflow, $payload, async: false);

            Http::assertSent(function(Request $request) use ($key, $payload) {
                $timestamp = $request->header('X-N8N-Timestamp')[0] ?? '';
                $signature = $request->header('X-N8N-Signature')[0] ?? '';

                $jsonBody = json_encode($payload);
                $bodyHash = hash('sha256', $jsonBody);
                $message  = "{$timestamp}.{$bodyHash}";
                $expected = 'sha256=' . hash_hmac('sha256', $message, $key);

                return $signature === $expected;
            });
        });

        it('does not send auth headers when key is null', function() {
            Http::fake(['*' => Http::response([], 200)]);

            $workflow = N8nWorkflow::factory()->create([
                'n8n_instance' => 'default',
                'webhook_path' => 'hmac-no-key',
                'auth_type'    => WebhookAuthType::HmacSha256,
                'auth_key'     => null,
            ]);

            $this->dispatcher->trigger($workflow, [], async: false);

            Http::assertSent(function(Request $request) {
                return ! $request->hasHeader('X-N8N-Signature');
            });
        });
    });

    // ── Rate limiting ─────────────────────────────────────────────────────────

    describe('rate limiting (sync)', function() {
        it('marks delivery failed immediately when sync trigger is rate limited', function() {
            Http::fake(['*' => Http::response([], 200)]);

            $this->workflow->update(['rate_limit' => 1]);
            $wf = $this->workflow->fresh();

            // Exhaust the bucket
            $this->dispatcher->trigger($wf, ['first' => true], async: false);

            // Second call is rate limited
            $delivery = $this->dispatcher->trigger($wf, ['second' => true], async: false);

            expect($delivery->fresh()->status)->toBe(DeliveryStatus::Failed)
                ->and($delivery->fresh()->error_message)->toContain('Rate limited');
        });

        it('does not make HTTP call when sync trigger is rate limited', function() {
            Http::fake(['*' => Http::response([], 200)]);

            $this->workflow->update(['rate_limit' => 1]);
            $wf = $this->workflow->fresh();

            $this->dispatcher->trigger($wf, [], async: false); // first — OK
            $this->dispatcher->trigger($wf, [], async: false); // second — limited

            // Only one HTTP request should have been sent
            Http::assertSentCount(1);
        });

        it('allows unlimited calls when rate limit is 0', function() {
            Http::fake(['*' => Http::response([], 200)]);

            $this->workflow->update(['rate_limit' => 0]);
            $wf = $this->workflow->fresh();

            $d1 = $this->dispatcher->trigger($wf, [], async: false);
            $d2 = $this->dispatcher->trigger($wf, [], async: false);
            $d3 = $this->dispatcher->trigger($wf, [], async: false);

            expect($d1->fresh()->status)->toBe(DeliveryStatus::Done)
                ->and($d2->fresh()->status)->toBe(DeliveryStatus::Done)
                ->and($d3->fresh()->status)->toBe(DeliveryStatus::Done);
        });
    });
});
