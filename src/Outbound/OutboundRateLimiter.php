<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Outbound;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Oriceon\N8nBridge\Models\N8nWorkflow;

/**
 * Controls how many outbound requests per minute are dispatched to n8n.
 *
 * Each workflow has its own rate-limit bucket keyed by workflow ID.
 * The limit (requests/minute) is resolved from:
 *   1. `N8nWorkflow::$rate_limit` (per-workflow override)
 *   2. `n8n-bridge.outbound.rate_limit` config (global default)
 *
 * Returning 0 from either source means unlimited.
 *
 * Uses atomic increment-first to prevent TOCTOU race conditions
 * when multiple queue workers check the same workflow simultaneously.
 *
 * Usage:
 *   $wait = $rateLimiter->check($workflow);
 *   if ($wait !== null) {
 *       // rate limited — wait $wait seconds before retrying
 *   }
 */
final class OutboundRateLimiter
{
    private const int DECAY_SECONDS = 60;

    /**
     * Check whether the workflow is allowed to fire another outbound request.
     *
     * Uses hit-first-then-check to prevent TOCTOU race conditions when
     * multiple queue workers check the same workflow simultaneously.
     *
     * Old pattern (vulnerable):
     *   Worker A: check(9 < 10) → pass → hit → 10
     *   Worker B: check(9 < 10) → pass → hit → 11  ← EXCEEDED!
     *
     * New pattern (atomic):
     *   Worker A: hit → 10, check(10 < 11) → pass
     *   Worker B: hit → 11, check(11 >= 11) → decrement → 10, blocked ✓
     *
     * @return int|null null if the request is allowed; seconds to wait if limited
     */
    public function check(N8nWorkflow $workflow): ?int
    {
        $limit = $workflow->effectiveRateLimit();

        if ($limit === 0) {
            return null; // unlimited
        }

        $key = "n8n_outbound_rl:{$workflow->id}";

        // Atomic: increment first, then check.
        // tooManyAttempts use >= so we check against $limit + 1
        // to allow exactly $limit requests per window.
        RateLimiter::hit($key, self::DECAY_SECONDS);

        if (RateLimiter::tooManyAttempts($key, $limit + 1)) {
            // Over limit — undo the speculative hit and report wait time
            $this->decrement($key);

            return max(1, RateLimiter::availableIn($key));
        }

        return null;
    }

    /**
     * Reset the rate-limit bucket for a workflow (useful in tests).
     */
    public function reset(N8nWorkflow $workflow): void
    {
        RateLimiter::clear("n8n_outbound_rl:{$workflow->id}");
    }

    /**
     * Decrement the rate limiter counter.
     *
     * Laravel's RateLimiter stores the hit count in a cache with a
     * key pattern of {key}:{timer}. We determined the counter to
     * undo the speculative hit when the limit was exceeded.
     */
    private function decrement(string $key): void
    {
        $store = Cache::getStore();

        if (method_exists($store, 'decrement')) {
            Cache::decrement($key);
        }
    }
}
