<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Inbound\Pipeline;

use Illuminate\Cache\RateLimiter;
use Illuminate\Http\Request;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

final readonly class RateLimitPipe
{
    /**
     * @param RateLimiter $limiter
     */
    public function __construct(private readonly RateLimiter $limiter)
    {
    }

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

        $key       = "n8nbr_rl_{$endpoint->slug}";
        $maxPerMin = $endpoint->rate_limit;

        if ($this->limiter->tooManyAttempts($key, $maxPerMin)) {
            $retryAfter = $this->limiter->availableIn($key);

            throw new TooManyRequestsHttpException($retryAfter, "Rate limit exceeded for endpoint [{$endpoint->slug}]. Retry after {$retryAfter}s.");
        }

        $this->limiter->hit($key, 60);

        return $next($passable);
    }
}
