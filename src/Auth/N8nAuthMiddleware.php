<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Auth;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Middleware that enforces authentication on ALL /n8n/* routes.
 *
 * Every request to /n8n/* MUST carry a valid X-N8N-Key header.
 * There are no public /n8n routes — not even the schema endpoint.
 *
 * The authenticated N8nCredential is bound to the request as an attribute,
 * so controllers can access it without re-authenticating:
 *
 *   $credential = $request->attributes->get('n8n_credential');
 */
final readonly class N8nAuthMiddleware
{
    /**
     * @param CredentialAuthService $auth
     */
    public function __construct(
        private CredentialAuthService $auth,
    ) {
    }

    /**
     * @param Request $request
     * @param \Closure $next
     * @return Response
     */
    public function handle(Request $request, \Closure $next): Response
    {
        [$credential, $reason] = $this->auth->authenticate($request);

        if ($credential === null) {
            $status  = $reason === 'ip_not_allowed' ? 403 : 401;
            $message = match ($reason) {
                'missing_key'    => 'Missing X-N8N-Key header.',
                'invalid_key'    => 'Invalid API key.',
                'ip_not_allowed' => 'Forbidden.',
                default          => 'Unauthorized.',
            };

            return response()->json(['error' => $message], $status);
        }

        // Attach resolved credential to request for use in controllers
        $request->attributes->set('n8n_credential', $credential);

        return $next($request);
    }
}
