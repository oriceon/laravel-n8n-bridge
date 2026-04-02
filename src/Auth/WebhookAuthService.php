<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Auth;

use Oriceon\N8nBridge\Enums\WebhookAuthType;
use Oriceon\N8nBridge\Models\N8nWorkflow;
use Random\RandomException;

/**
 * Builds HTTP authentication headers for outbound Laravel → n8n requests.
 *
 * Supports four strategies (see WebhookAuthType). The key is stored
 * encrypted in the workflow and decrypted on-the-fly here — it is never
 * persisted in plaintext.
 */
final class WebhookAuthService
{
    /**
     * Build auth headers to merge into an outbound HTTP request.
     *
     * Returns an empty array when:
     * - auth type is `none`
     * - auth_key is null / empty (misconfiguration is silent — no header added)
     *
     * @param  N8nWorkflow  $workflow  the workflow being triggered
     * @param  string       $jsonBody  the serialised JSON payload (used for HMAC signing)
     * @return array<string, string>   header name → value pairs
     */
    public function buildHeaders(N8nWorkflow $workflow, string $jsonBody): array
    {
        $type = $workflow->auth_type ?? WebhookAuthType::None;

        if ($type === WebhookAuthType::None) {
            return [];
        }

        // auth_key is stored encrypted; the model casts it back to plaintext.
        $key = $workflow->auth_key;

        if (empty($key)) {
            return [];
        }

        return match ($type) {
            WebhookAuthType::HeaderToken => [
                'X-N8N-Workflow-Key' => $key,
            ],

            WebhookAuthType::Bearer => [
                'Authorization' => "Bearer {$key}",
            ],

            WebhookAuthType::HmacSha256 => $this->buildHmacHeaders($key, $jsonBody),

            WebhookAuthType::None => [],
        };
    }

    /**
     * Generate a cryptographically secure random outbound key.
     *
     * Returns a 64-character lowercase hex string (256 bits of entropy).
     * Store the return value in `workflow->auth_key` — the model's
     * `encrypted` cast will encrypt it automatically on save.
     * @throws RandomException
     */
    public static function generateKey(): string
    {
        return bin2hex(random_bytes(32));
    }

    // ── Private ───────────────────────────────────────────────────────────────

    /**
     * HMAC-SHA256 headers with timestamp for replay protection.
     *
     * Signature covers: "{timestamp}.{sha256(body)}"
     * This prevents replaying a captured request even with a valid signature,
     * because the timestamp is part of the signed message.
     *
     * @param  string  $key       plaintext secret (hex string recommended)
     * @param  string  $jsonBody  raw JSON body that will be sent
     * @return array<string, string>
     */
    private function buildHmacHeaders(string $key, string $jsonBody): array
    {
        $timestamp = (string) time();
        $bodyHash  = hash('sha256', $jsonBody);
        $message   = "{$timestamp}.{$bodyHash}";
        $signature = hash_hmac('sha256', $message, $key);

        return [
            'X-N8N-Timestamp' => $timestamp,
            'X-N8N-Signature' => "sha256={$signature}",
        ];
    }
}
