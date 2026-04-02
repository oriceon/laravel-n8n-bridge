<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Oriceon\N8nBridge\Concerns\HasDynamicTable;
use Oriceon\N8nBridge\Concerns\HasPublicUuid;
use Oriceon\N8nBridge\Enums\EloquentEvent;

/**
 * Subscribes Laravel events / Eloquent events to n8n workflow triggers.
 *
 * When a matching event fires, the package automatically calls
 * N8nOutboundDispatcher to trigger the associated workflow.
 *
 * @property int               $id
 * @property int               $workflow_id
 * @property string            $event_class
 * @property string|null       $eloquent_model
 * @property EloquentEvent|null $eloquent_event
 * @property array|null        $conditions
 * @property bool              $queue_dispatch
 * @property string            $queue_name
 * @property bool              $is_active
 */
#[Fillable([
    'uuid',
    'workflow_id',
    'event_class',
    'eloquent_model',
    'eloquent_event',
    'conditions',
    'queue_dispatch',
    'queue_name',
    'is_active',
])]
class N8nEventSubscription extends Model
{
    use HasDynamicTable;
    use HasPublicUuid;

    protected function casts(): array
    {
        return [
            'eloquent_event' => EloquentEvent::class,
            'conditions'     => 'array',
            'queue_dispatch' => 'boolean',
            'is_active'      => 'boolean',
        ];
    }

    protected $attributes = [
        'queue_dispatch' => true,
        'queue_name'     => 'default',
        'is_active'      => true,
    ];

    // ── Scopes ────────────────────────────────────────────────────────────────

    /**
     * @param Builder $query
     * @return Builder
     */
    #[Scope]
    protected function active(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    /**
     * @param Builder $query
     * @param string $eventClass
     * @return Builder
     */
    #[Scope]
    protected function forEvent(Builder $query, string $eventClass): Builder
    {
        return $query->where('event_class', $eventClass);
    }

    /**
     * @param Builder $query
     * @param string $modelClass
     * @return Builder
     */
    #[Scope]
    protected function forModel(Builder $query, string $modelClass): Builder
    {
        return $query->where('eloquent_model', $modelClass);
    }

    // ── Relations ────────────────────────────────────────────────────────────────

    public function workflow(): BelongsTo
    {
        return $this->belongsTo(N8nWorkflow::class, 'workflow_id');
    }

    // ── Helpers ────────────────────────────────────────────────────────────────

    /**
     * @param array $data
     * @return bool
     */
    public function matchesConditions(array $data): bool
    {
        if (empty($this->conditions)) {
            return true;
        }

        foreach ($this->conditions as $key => $value) {
            if ($value !== data_get($data, $key)) {
                return false;
            }
        }

        return true;
    }

    protected function getTableBaseName(): string
    {
        return 'event_subscriptions__lists';
    }
}
