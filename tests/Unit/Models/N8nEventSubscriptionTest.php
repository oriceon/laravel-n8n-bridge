<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\EloquentEvent;
use Oriceon\N8nBridge\Models\N8nEventSubscription;
use Oriceon\N8nBridge\Models\N8nWorkflow;

covers(N8nEventSubscription::class);

beforeEach(function() {
    $this->workflow = N8nWorkflow::factory()->create();
});

function makeSub(N8nWorkflow $workflow, array $overrides = []): N8nEventSubscription
{
    return N8nEventSubscription::create(array_merge([
        'workflow_id'    => $workflow->id,
        'event_class'    => 'App\\Events\\OrderPlaced',
        'eloquent_model' => 'App\\Models\\Order',
        'eloquent_event' => EloquentEvent::Created->value,
        'is_active'      => true,
    ], $overrides));
}

// ── matchesConditions() ───────────────────────────────────────────────────────

describe('N8nEventSubscription::matchesConditions()', function() {
    it('returns true when conditions are empty', function() {
        $sub = makeSub($this->workflow, ['conditions' => null]);
        expect($sub->matchesConditions(['any' => 'data']))->toBeTrue();
    });

    it('returns true when all conditions match', function() {
        $sub = makeSub($this->workflow, ['conditions' => ['status' => 'paid', 'currency' => 'USD']]);

        expect($sub->matchesConditions(['status' => 'paid', 'currency' => 'USD', 'amount' => 100]))->toBeTrue();
    });

    it('returns false when a condition does not match', function() {
        $sub = makeSub($this->workflow, ['conditions' => ['status' => 'paid']]);

        expect($sub->matchesConditions(['status' => 'pending']))->toBeFalse();
    });

    it('supports dot notation for nested data', function() {
        $sub = makeSub($this->workflow, ['conditions' => ['customer.country' => 'RO']]);

        expect($sub->matchesConditions(['customer' => ['country' => 'RO']]))->toBeTrue()
            ->and($sub->matchesConditions(['customer' => ['country' => 'US']]))->toBeFalse();
    });
});

// ── Scopes ────────────────────────────────────────────────────────────────────

describe('N8nEventSubscription scopes', function() {
    it('active() returns only active subscriptions', function() {
        makeSub($this->workflow, ['is_active' => true]);
        makeSub($this->workflow, ['is_active' => false, 'event_class' => 'Other\\Event']);

        expect(N8nEventSubscription::active()->count())->toBe(1);
    });

    it('forEvent() filters by event class', function() {
        makeSub($this->workflow, ['event_class' => 'App\\Events\\OrderPlaced']);
        makeSub($this->workflow, ['event_class' => 'App\\Events\\OrderShipped']);

        expect(N8nEventSubscription::forEvent('App\\Events\\OrderPlaced')->count())->toBe(1);
    });

    it('forModel() filters by eloquent model class', function() {
        makeSub($this->workflow, ['eloquent_model' => 'App\\Models\\Order']);
        makeSub($this->workflow, ['eloquent_model' => 'App\\Models\\Invoice', 'event_class' => 'Other']);

        expect(N8nEventSubscription::forModel('App\\Models\\Order')->count())->toBe(1);
    });
});

// ── Casts ─────────────────────────────────────────────────────────────────────

it('casts eloquent_event to EloquentEvent enum', function() {
    $sub = makeSub($this->workflow);
    expect($sub->eloquent_event)->toBe(EloquentEvent::Created);
});

it('casts conditions to array', function() {
    $sub = makeSub($this->workflow, ['conditions' => ['key' => 'val']]);
    expect($sub->conditions)->toBe(['key' => 'val']);
});
