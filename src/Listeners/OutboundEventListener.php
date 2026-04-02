<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Listeners;

use Oriceon\N8nBridge\Models\N8nEventSubscription;
use Oriceon\N8nBridge\Outbound\N8nOutboundDispatcher;

/**
 * Dynamic event listener: reads subscriptions from DB and fires outbound triggers.
 *
 * Registered as a wildcard listener. Guards against framework-internal events
 * (eloquent.*, illuminate.*, bootstrapping:*, booted:*) that fire during the
 * Laravel boot cycle — querying a model at that point causes the
 * "bootIfNotBooted called while booting" LogicException introduced in Laravel 13.
 */
final readonly class OutboundEventListener
{
    /**
     * @param N8nOutboundDispatcher $dispatcher
     */
    public function __construct(
        private N8nOutboundDispatcher $dispatcher,
    ) {
    }

    /**
     * Called for every fired event.
     * Skips internal framework events, then checks DB for active subscriptions.
     *
     * @param string $eventClass
     * @param array $payload
     */
    public function handle(string $eventClass, array $payload): void
    {
        // Guard: skip all framework-internal events that fire during boot.
        // These include eloquent.booting, eloquent.booted, illuminate.*, etc.
        // Querying a model while Eloquent is still registering itself causes
        // a LogicException in Laravel 13.
        if ($this->isInternalEvent($eventClass)) {
            return;
        }

        $subscriptions = N8nEventSubscription::query()
            ->with('workflow')
            ->active()
            ->forEvent($eventClass)
            ->get();

        foreach ($subscriptions as $subscription) {
            $workflow = $subscription->workflow;

            if ($workflow === null || ! $workflow->is_active || ! $workflow->canSend()) {
                continue;
            }

            $eventData = $this->extractData($payload[0] ?? null);

            if ( ! $subscription->matchesConditions($eventData)) {
                continue;
            }

            $this->dispatcher->trigger(
                workflow: $workflow,
                payload:  $eventData,
                async:    $subscription->queue_dispatch,
            );
        }
    }

    // ── Internals ─────────────────────────────────────────────────────────────

    /**
     * Returns true for framework-internal event names that must never
     * trigger a DB query. These fire during the boot/resolve cycle.
     *
     * @param string $eventClass
     * @return bool
     */
    private function isInternalEvent(string $eventClass): bool
    {
        // All framework-internal events follow these patterns:
        //   eloquent.booting: App\Models\User
        //   eloquent.booted: App\Models\User
        //   eloquent.retrieved: App\Models\User
        //   illuminate.something
        //   bootstrapping: Illuminate\Foundation\...
        //   Illuminate\Foundation\Http\Events\RequestHandled
        return str_starts_with($eventClass, 'eloquent.') ||
            str_starts_with($eventClass, 'illuminate.') ||
            str_starts_with($eventClass, 'Illuminate\\') ||
            str_starts_with($eventClass, 'bootstrapping:') ||
            str_starts_with($eventClass, 'bootstrapped:') ||
            str_starts_with($eventClass, 'booting:') ||
            str_starts_with($eventClass, 'booted:') ||
            str_starts_with($eventClass, 'Laravel\\');
    }

    private function extractData(mixed $event): array
    {
        if ($event === null) {
            return [];
        }

        if (method_exists($event, 'toN8nPayload')) {
            return (array) $event->toN8nPayload();
        }

        if (method_exists($event, 'toArray')) {
            return (array) $event->toArray();
        }

        $data       = [];
        $reflection = new \ReflectionClass($event);

        foreach ($reflection->getProperties(\ReflectionProperty::IS_PUBLIC) as $prop) {
            $name = $prop->getName();
            $val  = $prop->getValue($event);

            if (method_exists($val, 'toArray')) {
                $data[$name] = $val->toArray();
            }
            elseif (is_scalar($val) || $val === null) {
                $data[$name] = $val;
            }
        }

        return $data;
    }
}
