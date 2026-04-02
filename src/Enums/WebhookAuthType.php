<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Enums;

/**
 * Authentication strategy for outbound requests: Laravel → n8n.
 *
 * | Type         | Header(s) sent                                            | n8n verification         |
 * |--------------|-----------------------------------------------------------|--------------------------|
 * | none         | —                                                         | Open webhook             |
 * | header_token | X-N8N-Workflow-Key: <token>                               | Header Auth credential   |
 * | bearer       | Authorization: Bearer <token>                             | Header Auth credential   |
 * | hmac_sha256  | X-N8N-Timestamp + X-N8N-Signature: sha256=<hmac>          | Code node (see docs)     |
 *
 * The key/token is stored encrypted (AES-256-CBC) in workflows.auth_key.
 */
enum WebhookAuthType: string
{
    /** No authentication header — use only for trusted internal networks. */
    case None = 'none';

    /**
     * Static bearer token sent as a custom header.
     * n8n credential: Header Auth → Name: X-N8N-Workflow-Key, Value: <token>
     */
    case HeaderToken = 'header_token';

    /**
     * RFC 6750 Bearer token.
     * n8n credential: Header Auth → Name: Authorization, Value: Bearer <token>
     */
    case Bearer = 'bearer';

    /**
     * HMAC-SHA256 body signature with timestamp for replay protection.
     *
     * Sent headers:
     *   X-N8N-Timestamp: <unix_timestamp>
     *   X-N8N-Signature: sha256=<hex_hmac>
     *
     * Signature input: "{timestamp}.{sha256(body)}"
     *
     * Verify in n8n with a Code node before the main logic:
     *   const ts  = $input.params.header['x-n8n-timestamp'];
     *   const sig = $input.params.header['x-n8n-signature'];
     *   const body = JSON.stringify($input.item.json);
     *   const expected = 'sha256=' + require('crypto')
     *     .createHmac('sha256', $env.WORKFLOW_SECRET)
     *     .update(`${ts}.${require('crypto').createHash('sha256').update(body).digest('hex')}`)
     *     .digest('hex');
     *   if (sig !== expected) throw new Error('Invalid signature');
     */
    case HmacSha256 = 'hmac_sha256';

    public function label(): string
    {
        return match ($this) {
            self::None        => 'None',
            self::HeaderToken => 'Header Token (X-N8N-Workflow-Key)',
            self::Bearer      => 'Bearer Token (Authorization)',
            self::HmacSha256  => 'HMAC-SHA256 Signature',
        };
    }

    /** Whether this auth type requires an auth_key to be configured. */
    public function requiresKey(): bool
    {
        return $this !== self::None;
    }
}
