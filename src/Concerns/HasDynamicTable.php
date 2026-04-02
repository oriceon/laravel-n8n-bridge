<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Concerns;

use Illuminate\Support\Str;

/**
 * Automatically sets the table name from the config prefix.
 * Usage: override $tableBaseName in a child model.
 *
 * Convention: {prefix}__{group}__{entity}
 * Default: n8n__workflows__lists, n8n__endpoints__lists, etc.
 */
trait HasDynamicTable
{
    public function initializeHasDynamicTable(): void
    {
        $prefix      = config('n8n-bridge.table_prefix', 'n8n');
        $this->table = $prefix . '__' . $this->getTableBaseName();
    }

    /**
     * Override in a child model to define the base name (without a prefix).
     * Example: 'workflows', 'endpoints', 'deliveries'
     */
    protected function getTableBaseName(): string
    {
        // Derive from the class name by default: N8nWorkflow → workflows
        $class = class_basename(static::class);

        // Strip "N8n" prefix if present, then snake_case + pluralize
        $name = preg_replace('/^N8n/', '', $class) ?? $class;

        return Str::snake(Str::plural($name));
    }
}
