<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Auth\WebhookAuthService;
use Oriceon\N8nBridge\Enums\WebhookAuthType;
use Oriceon\N8nBridge\Models\N8nWorkflow;

covers(WebhookAuthService::class);

describe('WebhookAuthService', function() {

    beforeEach(function() {
        $this->service = new WebhookAuthService();
    });

    // ── generateKey ────────────────────────────────────────────────────────────

    describe('generateKey()', function() {
        it('returns a 64-char hex string', function() {
            $key = WebhookAuthService::generateKey();

            expect($key)->toBeString()
                ->toHaveLength(64)
                ->toMatch('/^[0-9a-f]+$/');
        });

        it('generates unique keys on each call', function() {
            expect(WebhookAuthService::generateKey())
                ->not->toBe(WebhookAuthService::generateKey());
        });
    });

    // ── none ───────────────────────────────────────────────────────────────────

    describe('auth type: none', function() {

        it('returns empty array', function() {
            $workflow = N8nWorkflow::factory()->make([
                'auth_type' => WebhookAuthType::None,
                'auth_key'  => null,
            ]);

            expect($this->service->buildHeaders($workflow, '{}'))->toBe([]);
        });

        it('returns empty array even when auth_key is set', function() {
            $workflow = N8nWorkflow::factory()->make([
                'auth_type' => WebhookAuthType::None,
                'auth_key'  => 'some-key',
            ]);

            expect($this->service->buildHeaders($workflow, '{}'))->toBe([]);
        });
    });

    // ── header_token ───────────────────────────────────────────────────────────

    describe('auth type: header_token', function() {
        it('returns X-N8N-Workflow-Key header with the plaintext key', function() {
            $workflow = N8nWorkflow::factory()->make([
                'auth_type' => WebhookAuthType::HeaderToken,
                'auth_key'  => 'my-secret-token',
            ]);

            $headers = $this->service->buildHeaders($workflow, '{}');

            expect($headers)->toBe(['X-N8N-Workflow-Key' => 'my-secret-token']);
        });

        it('returns empty array when key is null', function() {
            $workflow = N8nWorkflow::factory()->make([
                'auth_type' => WebhookAuthType::HeaderToken,
                'auth_key'  => null,
            ]);

            expect($this->service->buildHeaders($workflow, '{}'))->toBe([]);
        });

        it('returns empty array when key is empty string', function() {
            $workflow = N8nWorkflow::factory()->make([
                'auth_type' => WebhookAuthType::HeaderToken,
                'auth_key'  => '',
            ]);

            expect($this->service->buildHeaders($workflow, '{}'))->toBe([]);
        });
    });

    // ── bearer ────────────────────────────────────────────────────────────────

    describe('auth type: bearer', function() {
        it('returns Authorization Bearer header', function() {
            $workflow = N8nWorkflow::factory()->make([
                'auth_type' => WebhookAuthType::Bearer,
                'auth_key'  => 'bearer-token-xyz',
            ]);

            $headers = $this->service->buildHeaders($workflow, '{}');

            expect($headers)->toBe(['Authorization' => 'Bearer bearer-token-xyz']);
        });

        it('returns empty array when key is null', function() {
            $workflow = N8nWorkflow::factory()->make([
                'auth_type' => WebhookAuthType::Bearer,
                'auth_key'  => null,
            ]);

            expect($this->service->buildHeaders($workflow, '{}'))->toBe([]);
        });
    });

    // ── hmac_sha256 ───────────────────────────────────────────────────────────

    describe('auth type: hmac_sha256', function() {
        it('returns X-N8N-Timestamp and X-N8N-Signature headers', function() {
            $workflow = N8nWorkflow::factory()->make([
                'auth_type' => WebhookAuthType::HmacSha256,
                'auth_key'  => 'hmac-secret-key',
            ]);

            $headers = $this->service->buildHeaders($workflow, '{"foo":"bar"}');

            expect($headers)->toHaveKeys(['X-N8N-Timestamp', 'X-N8N-Signature']);
        });

        it('signature starts with sha256=', function() {
            $workflow = N8nWorkflow::factory()->make([
                'auth_type' => WebhookAuthType::HmacSha256,
                'auth_key'  => 'hmac-secret-key',
            ]);

            $headers = $this->service->buildHeaders($workflow, '{}');

            expect($headers['X-N8N-Signature'])->toStartWith('sha256=');
        });

        it('timestamp is a valid unix timestamp', function() {
            $before   = time();
            $workflow = N8nWorkflow::factory()->make([
                'auth_type' => WebhookAuthType::HmacSha256,
                'auth_key'  => 'hmac-secret-key',
            ]);
            $headers  = $this->service->buildHeaders($workflow, '{}');
            $after    = time();

            $ts = (int) $headers['X-N8N-Timestamp'];

            expect($ts)->toBeGreaterThanOrEqual($before)
                ->toBeLessThanOrEqual($after);
        });

        it('signature is verifiable with the same key and body', function() {
            $key      = 'verifiable-secret';
            $body     = '{"order_id":42}';
            $workflow = N8nWorkflow::factory()->make([
                'auth_type' => WebhookAuthType::HmacSha256,
                'auth_key'  => $key,
            ]);

            $headers   = $this->service->buildHeaders($workflow, $body);
            $timestamp = $headers['X-N8N-Timestamp'];
            $bodyHash  = hash('sha256', $body);
            $message   = "{$timestamp}.{$bodyHash}";
            $expected  = 'sha256=' . hash_hmac('sha256', $message, $key);

            expect($headers['X-N8N-Signature'])->toBe($expected);
        });

        it('different bodies produce different signatures', function() {
            $workflow = N8nWorkflow::factory()->make([
                'auth_type' => WebhookAuthType::HmacSha256,
                'auth_key'  => 'same-key',
            ]);

            $h1 = $this->service->buildHeaders($workflow, '{"a":1}');
            $h2 = $this->service->buildHeaders($workflow, '{"a":2}');

            expect($h1['X-N8N-Signature'])->not->toBe($h2['X-N8N-Signature']);
        });

        it('returns empty array when key is null', function() {
            $workflow = N8nWorkflow::factory()->make([
                'auth_type' => WebhookAuthType::HmacSha256,
                'auth_key'  => null,
            ]);

            expect($this->service->buildHeaders($workflow, '{}'))->toBe([]);
        });
    });
});
