<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Inbound\Pipeline;

use Illuminate\Http\Request;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

final readonly class IpWhitelistPipe
{
    /**
     * @param array $passable
     * @param \Closure $next
     * @return mixed
     */
    public function handle(array $passable, \Closure $next): mixed
    {
        /** @var Request $request */
        /** @var N8nEndpoint $endpoint */
        [$request, $endpoint] = $passable;

        $allowedIps = $endpoint->allowed_ips ?? [];

        if (empty($allowedIps)) {
            return $next($passable);
        }

        $clientIp = $request->ip() ?? '';

        if ( ! in_array($clientIp, $allowedIps, strict: true)) {
            throw new AccessDeniedHttpException("IP [{$clientIp}] is not whitelisted for endpoint [{$endpoint->slug}].");
        }

        return $next($passable);
    }
}
