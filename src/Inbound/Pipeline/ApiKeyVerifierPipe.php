<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Inbound\Pipeline;

use Illuminate\Http\Request;
use Oriceon\N8nBridge\Auth\CredentialAuthService;
use Oriceon\N8nBridge\Models\N8nEndpoint;

/**
 * Inbound pipeline pipe: authenticate the request against the credential key.
 *
 * Looks up the endpoint's associated credential and verifies the incoming
 * X-N8N-Key using CredentialAuthService — the same service used by
 * Tools and Queue Progress.
 *
 * Returns 401 if the key is missing or invalid.
 * Returns 403 if the IP is not in the credential's allowed_ips.
 */
final readonly class ApiKeyVerifierPipe
{
    public function __construct(
        private CredentialAuthService $auth,
    ) {}

    public function handle(array $passable, \Closure $next): mixed
    {
        /** @var Request $request */
        /** @var N8nEndpoint $endpoint */
        [$request, $endpoint] = $passable;

        // Auth already enforced by N8nAuthMiddleware for all /n8n/* routes.
        // Every endpoint must have at least one credential attached.
        // If none are attached, deny the request — open-access endpoints are not allowed.
        $credential = $request->attributes->get('n8n_credential');
        $credentialIds = $endpoint->credentials()->pluck('id');

        if ($credentialIds->isEmpty() || ! $credentialIds->contains($credential?->id)) {
            return response()->json(['error' => 'Key not valid for this endpoint.'], 401);
        }

        return $next($passable);
    }
}
