<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Concerns;

use Oriceon\N8nBridge\Enums\EloquentEvent;
use Oriceon\N8nBridge\Models\N8nEventSubscription;
use Oriceon\N8nBridge\Models\N8nWorkflow;
use Oriceon\N8nBridge\Outbound\N8nOutboundDispatcher;

/**
 * Trait for Eloquent models that trigger n8n workflows on CRUD events.
 *
 * Usage:
 *
 *   class Invoice extends Model
 *   {
 *       use TriggersN8nOnEvents;
 *
 *       protected array $n8nTriggers = [
 *           'created' => 'invoice-created',
 *           'updated' => [
 *               'workflow'  => 'invoice-updated',
 *               'only_when' => ['status', 'amount'],
 *           ],
 *       ];
 *
 *       // Optional — customize payload
 *       public function toN8nPayload(string $event): array
 *       {
 *           return ['id' => $this->id, 'event' => $event];
 *       }
 *   }
 */
trait TriggersN8nOnEvents
{
    public static function bootTriggersN8nOnEvents(): void
    {
        foreach (EloquentEvent::cases() as $event) {
            static::registerModelEvent(
                $event->value,
                static function(self $model) use ($event): void {
                    $model->fireN8nTrigger($event);
                }
            );
        }
    }

    /**
     * @param EloquentEvent $event
     * @return void
     */
    private function fireN8nTrigger(EloquentEvent $event): void
    {
        $triggers = $this->n8nTriggers ?? [];
        $config   = $triggers[$event->value] ?? null;

        if ($config === null) {
            return;
        }

        // Check 'only_when' — skip if none of the watched fields changed
        if (is_array($config) && isset($config['only_when']) && $event === EloquentEvent::Updated) {
            $watchedFields = (array) $config['only_when'];
            $changed       = array_keys($this->getDirty());

            if (empty(array_intersect($watchedFields, $changed))) {
                return;
            }
        }

        // Look up workflow by name in DB-driven subscriptions
        $workflowName = is_array($config) ? ($config['workflow'] ?? null) : $config;

        if ($workflowName === null) {
            return;
        }

        // Find active subscriptions for this model + event
        $subscriptions = N8nEventSubscription::query()
            ->with('workflow')
            ->active()
            ->forModel(static::class)
            ->where('eloquent_event', $event->value)
            ->get();

        if ($subscriptions->isEmpty()) {
            // Fallback: look up by workflow name directly
            $workflow = N8nWorkflow::query()
                ->active()
                ->where('name', $workflowName)
                ->first();

            if ($workflow === null) {
                return;
            }

            $payload = $this->buildN8nPayload($event->value);
            app(N8nOutboundDispatcher::class)->trigger($workflow, $payload);

            return;
        }

        $payload = $this->buildN8nPayload($event->value);

        foreach ($subscriptions as $subscription) {
            if ($subscription->workflow && $subscription->matchesConditions($payload)) {
                app(N8nOutboundDispatcher::class)->trigger(
                    $subscription->workflow,
                    $payload,
                    $subscription->queue_dispatch,
                );
            }
        }
    }

    /**
     * @param string $event
     * @return array
     */
    private function buildN8nPayload(string $event): array
    {
        if (method_exists($this, 'toN8nPayload')) {
            return $this->toN8nPayload($event);
        }

        return array_merge($this->toArray(), [
            'event'        => $event,
            'model'        => static::class,
            'triggered_at' => now()->toIso8601String(),
        ]);
    }
}
