<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Concerns;

use Illuminate\Support\Str;

/**
 * Generates and manages a public-facing UUID v7 column called `uuid`.
 *
 * Architecture:
 *   id   (BIGINT auto-increment) — primary key, all relations, all JOINs
 *   uuid (CHAR(36))              — exposed in URLs, never used in FK
 *
 * The `uuid` column is auto-generated before insert using UUID v4 via Str::uuid().
 * For time-ordered IDs, swap to Str::orderedUuid() (UUID v6) or Str::ulid() (ULID).
 *
 * Usage in controllers / routes:
 *   Route::get('/workflows/{workflow}', ...) // resolved via uuid
 *
 * Usage in Eloquent relations:
 *   $workflow->endpoints() // uses id (BIGINT)
 */
trait HasPublicUuid
{
    public static function bootHasPublicUuid(): void
    {
        static::creating(static function(self $model): void {
            if (empty($model->uuid)) {
                // Str::uuid() generates a random UUID v4
                $model->uuid = (string) Str::uuid();
            }
        });
    }

    /**
     * Route model binding uses `uuid` so integer IDs are never exposed.
     */
    public function getRouteKeyName(): string
    {
        return 'uuid';
    }

    /**
     * Find the model by uuid — used in route binding.
     */
    public static function findByUuid(string $uuid): ?static
    {
        return static::where('uuid', $uuid)->first();
    }
}
