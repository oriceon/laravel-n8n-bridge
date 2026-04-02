<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\CircuitBreaker;

use Oriceon\N8nBridge\Enums\CircuitBreakerState;
use Oriceon\N8nBridge\Events\N8nCircuitBreakerOpenedEvent;
use Oriceon\N8nBridge\Models\N8nCircuitBreaker;
use Oriceon\N8nBridge\Models\N8nWorkflow;

/**
 * Manages per-workflow circuit breaker state in DB.
 *
 * State machine:
 *   Closed     → (N failures) → Open
 *   Open       → (cooldown elapsed) → HalfOpen
 *   HalfOpen   → (success) → Closed
 *   HalfOpen   → (failure) → Open
 */
final class CircuitBreakerManager
{
    public function getState(N8nWorkflow $workflow): CircuitBreakerState
    {
        $breaker = $this->ensureExists($workflow);

        // Auto-transition Open → HalfOpen after cooldown
        if ($breaker->shouldTransitionToHalfOpen()) {
            $breaker->update([
                'state'        => CircuitBreakerState::HalfOpen->value,
                'half_open_at' => now(),
            ]);

            return CircuitBreakerState::HalfOpen;
        }

        return $breaker->state;
    }

    /** Number of consecutive successes required in HalfOpen before closing. */
    private const int HALF_OPEN_SUCCESS_THRESHOLD = 2;

    public function recordSuccess(N8nWorkflow $workflow): void
    {
        $breaker = $this->ensureExists($workflow);

        // In HalfOpen, require multiple consecutive successes before closing
        if ($breaker->state === CircuitBreakerState::HalfOpen) {
            $probeSuccesses = $breaker->success_count + 1;

            if ($probeSuccesses < self::HALF_OPEN_SUCCESS_THRESHOLD) {
                $breaker->update(['success_count' => $probeSuccesses]);

                return;
            }
        }

        $breaker->update([
            'state'         => CircuitBreakerState::Closed->value,
            'failure_count' => 0,
            'success_count' => 0,
            'closed_at'     => now(),
        ]);
    }

    public function recordFailure(N8nWorkflow $workflow): CircuitBreakerState
    {
        $breaker   = $this->ensureExists($workflow);
        $newCount  = $breaker->failure_count + 1;
        $threshold = (int) config('n8n-bridge.circuit_breaker.threshold', 5);

        if ($newCount >= $threshold) {
            $breaker->update([
                'state'         => CircuitBreakerState::Open->value,
                'failure_count' => $newCount,
                'success_count' => 0,
                'opened_at'     => now(),
            ]);

            event(new N8nCircuitBreakerOpenedEvent($workflow, $newCount));

            return CircuitBreakerState::Open;
        }

        $breaker->update(['failure_count' => $newCount]);

        return CircuitBreakerState::Closed;
    }

    public function reset(N8nWorkflow $workflow): void
    {
        $this->ensureExists($workflow)->update([
            'state'         => CircuitBreakerState::Closed->value,
            'failure_count' => 0,
            'opened_at'     => null,
            'half_open_at'  => null,
            'closed_at'     => now(),
        ]);
    }

    private function ensureExists(N8nWorkflow $workflow): N8nCircuitBreaker
    {
        return N8nCircuitBreaker::firstOrCreate(
            ['workflow_id' => $workflow->id],
            [
                'state'         => CircuitBreakerState::Closed->value,
                'failure_count' => 0,
            ]
        );
    }
}
