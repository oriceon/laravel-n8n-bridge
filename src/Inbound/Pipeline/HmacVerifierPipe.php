<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Inbound\Pipeline;

use Illuminate\Http\Request;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;

final readonly class HmacVerifierPipe
{
    /** Maximum age of a signed request in seconds (5 minutes). */
    private const int MAX_TIMESTAMP_AGE = 300;

    public function handle(array $passable, \Closure $next): mixed
    {
        /** @var Request $request */
        /** @var N8nEndpoint $endpoint */
        [$request, $endpoint] = $passable;

        if ( ! $endpoint->verify_hmac || empty($endpoint->hmac_secret)) {
            return $next($passable);
        }

        $signature = $request->header('X-N8N-Signature');

        if ($signature === null) {
            throw new UnauthorizedHttpException('n8n-bridge', 'Missing X-N8N-Signature header.');
        }

        // Replay protection: check timestamp if present
        $timestamp = $request->header('X-N8N-Timestamp');

        if ($timestamp !== null) {
            if ( ! is_numeric($timestamp) || abs(time() - (int) $timestamp) > self::MAX_TIMESTAMP_AGE) {
                throw new UnauthorizedHttpException('n8n-bridge', 'Request timestamp expired or invalid.');
            }

            // When timestamp is provided, include it in the signed message
            $message = $timestamp . '.' . $request->getContent();
        }
        else {
            // Fallback: sign body only (backwards compatible)
            $message = $request->getContent();
        }

        $expected = 'sha256=' . hash_hmac(
            'sha256',
            $message,
            $endpoint->hmac_secret
        );

        if ( ! hash_equals($expected, $signature)) {
            throw new UnauthorizedHttpException('n8n-bridge', 'HMAC signature mismatch.');
        }

        return $next($passable);
    }
}
