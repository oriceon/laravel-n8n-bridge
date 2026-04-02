<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Oriceon\N8nBridge\CircuitBreaker\CircuitBreakerManager;
use Oriceon\N8nBridge\Enums\CircuitBreakerState;
use Oriceon\N8nBridge\Events\N8nCircuitBreakerOpenedEvent;
use Oriceon\N8nBridge\Models\N8nCircuitBreaker;
use Oriceon\N8nBridge\Models\N8nWorkflow;

covers(CircuitBreakerManager::class);

beforeEach(function() {
    $this->manager  = app(CircuitBreakerManager::class);
    $this->workflow = N8nWorkflow::factory()->create();

    config([
        'n8n-bridge.circuit_breaker.threshold'    => 3,
        'n8n-bridge.circuit_breaker.cooldown_sec' => 60,
    ]);
});

describe('State machine', function() {
    it('starts in Closed state', function() {
        expect($this->manager->getState($this->workflow))->toBe(CircuitBreakerState::Closed);
    });

    it('stays Closed under threshold', function() {
        $this->manager->recordFailure($this->workflow);
        $this->manager->recordFailure($this->workflow);

        expect($this->manager->getState($this->workflow))->toBe(CircuitBreakerState::Closed);
    });

    it('opens after reaching threshold failures', function() {
        $this->manager->recordFailure($this->workflow);
        $this->manager->recordFailure($this->workflow);
        $result = $this->manager->recordFailure($this->workflow);

        expect($result)->toBe(CircuitBreakerState::Open)
            ->and($this->manager->getState($this->workflow))->toBe(CircuitBreakerState::Open);
    });

    it('transitions to HalfOpen after cooldown', function() {
        for ($i = 0; $i < 3; ++$i) {
            $this->manager->recordFailure($this->workflow);
        }

        N8nCircuitBreaker::where('workflow_id', $this->workflow->id)
            ->update(['opened_at' => now()->subSeconds(61)]);

        $state = $this->manager->getState($this->workflow);
        expect($state)->toBe(CircuitBreakerState::HalfOpen)
            ->and($state->allowsRequests())->toBeTrue();
    });

    it('stays HalfOpen after first success probe', function() {
        for ($i = 0; $i < 3; ++$i) {
            $this->manager->recordFailure($this->workflow);
        }
        N8nCircuitBreaker::where('workflow_id', $this->workflow->id)->update([
            'opened_at' => now()->subSeconds(61),
            'state'     => CircuitBreakerState::HalfOpen->value,
        ]);

        $this->manager->recordSuccess($this->workflow);

        $breaker = N8nCircuitBreaker::where('workflow_id', $this->workflow->id)->first();
        expect($this->manager->getState($this->workflow))->toBe(CircuitBreakerState::HalfOpen)
            ->and($breaker->success_count)->toBe(1);
    });

    it('closes after multiple consecutive successes in HalfOpen', function() {
        for ($i = 0; $i < 3; ++$i) {
            $this->manager->recordFailure($this->workflow);
        }
        N8nCircuitBreaker::where('workflow_id', $this->workflow->id)->update([
            'opened_at' => now()->subSeconds(61),
            'state'     => CircuitBreakerState::HalfOpen->value,
        ]);

        // First success — still probing
        $this->manager->recordSuccess($this->workflow);

        // Second success — threshold reached, should close
        $this->manager->recordSuccess($this->workflow);

        $breaker = N8nCircuitBreaker::where('workflow_id', $this->workflow->id)->first();
        expect($this->manager->getState($this->workflow))->toBe(CircuitBreakerState::Closed)
            ->and($breaker->failure_count)->toBe(0)
            ->and($breaker->success_count)->toBe(0);
    });

    it('blocks requests when Open', function() {
        for ($i = 0; $i < 3; ++$i) {
            $this->manager->recordFailure($this->workflow);
        }

        expect($this->manager->getState($this->workflow)->allowsRequests())->toBeFalse();
    });
});

describe('Manual reset', function() {
    it('resets to Closed with zero failures', function() {
        for ($i = 0; $i < 3; ++$i) {
            $this->manager->recordFailure($this->workflow);
        }

        $this->manager->reset($this->workflow);

        $breaker = N8nCircuitBreaker::where('workflow_id', $this->workflow->id)->first();
        expect($this->manager->getState($this->workflow))->toBe(CircuitBreakerState::Closed)
            ->and($breaker->failure_count)->toBe(0);
    });
});

describe('Events', function() {
    it('fires N8nCircuitBreakerOpenedEvent when breaker opens', function() {
        Event::fake([N8nCircuitBreakerOpenedEvent::class]);

        for ($i = 0; $i < 3; ++$i) {
            $this->manager->recordFailure($this->workflow);
        }

        Event::assertDispatched(
            N8nCircuitBreakerOpenedEvent::class,
            fn($e) => $e->workflow->id === $this->workflow->id && $e->failureCount === 3
        );
    });
});

it('creates breaker record on first access', function() {
    expect(N8nCircuitBreaker::where('workflow_id', $this->workflow->id)->count())->toBe(0);

    $this->manager->getState($this->workflow);

    expect(N8nCircuitBreaker::where('workflow_id', $this->workflow->id)->count())->toBe(1);
});
