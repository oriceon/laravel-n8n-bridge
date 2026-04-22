<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Replace single credential_id FK on endpoints/tools with many-to-many pivot tables.
 *
 * Before: one credential per endpoint/tool (credential_id FK column).
 * After:  many credentials per endpoint/tool via pivot tables.
 *
 * Behaviour:
 *   - No credentials attached  → endpoint/tool accepts any authenticated caller.
 *   - 1+ credentials attached  → only keys from those credentials are accepted.
 *
 * Migration steps:
 *   1. Create pivot tables.
 *   2. Copy existing credential_id rows into pivot tables.
 *   3. Drop old credential_id columns.
 */
return new class() extends Migration {
    private string $p;

    public function __construct()
    {
        $this->p = config('n8n-bridge.table_prefix', 'n8n');
    }

    public function up(): void
    {
        $p = $this->p;

        // ── 1. Endpoint ↔ Credentials pivot ──────────────────────────────────
        Schema::create("{$p}__endpoints__credentials", static function(Blueprint $table) use ($p): void {
            $table->unsignedBigInteger('endpoint_id');
            $table->unsignedBigInteger('credential_id');

            $table->primary(['endpoint_id', 'credential_id']);

            $table->foreign('endpoint_id')
                ->references('id')
                ->on("{$p}__endpoints__lists")
                ->cascadeOnDelete();

            $table->foreign('credential_id')
                ->references('id')
                ->on("{$p}__credentials__lists")
                ->cascadeOnDelete();
        });

        // ── 2. Tool ↔ Credentials pivot ───────────────────────────────────────
        Schema::create("{$p}__tools__credentials", static function(Blueprint $table) use ($p): void {
            $table->unsignedBigInteger('tool_id');
            $table->unsignedBigInteger('credential_id');

            $table->primary(['tool_id', 'credential_id']);

            $table->foreign('tool_id')
                ->references('id')
                ->on("{$p}__tools__lists")
                ->cascadeOnDelete();

            $table->foreign('credential_id')
                ->references('id')
                ->on("{$p}__credentials__lists")
                ->cascadeOnDelete();
        });

        // ── 3. Migrate existing credential_id data ────────────────────────────
        DB::table("{$p}__endpoints__lists")
            ->whereNotNull('credential_id')
            ->get(['id', 'credential_id'])
            ->each(static function(object $row) use ($p): void {
                DB::table("{$p}__endpoints__credentials")->insertOrIgnore([
                    'endpoint_id'   => $row->id,
                    'credential_id' => $row->credential_id,
                ]);
            });

        DB::table("{$p}__tools__lists")
            ->whereNotNull('credential_id')
            ->get(['id', 'credential_id'])
            ->each(static function(object $row) use ($p): void {
                DB::table("{$p}__tools__credentials")->insertOrIgnore([
                    'tool_id'       => $row->id,
                    'credential_id' => $row->credential_id,
                ]);
            });

        // ── 4. Drop old credential_id columns ─────────────────────────────────
        Schema::table("{$p}__endpoints__lists", static function(Blueprint $table): void {
            $table->dropForeign(['credential_id']);
            $table->dropColumn('credential_id');
        });

        Schema::table("{$p}__tools__lists", static function(Blueprint $table): void {
            $table->dropForeign(['credential_id']);
            $table->dropColumn('credential_id');
        });
    }

    public function down(): void
    {
        $p = $this->p;

        // Restore credential_id columns
        Schema::table("{$p}__endpoints__lists", static function(Blueprint $table) use ($p): void {
            $table->unsignedBigInteger('credential_id')->nullable()->after('uuid');
            $table->foreign('credential_id')
                ->references('id')
                ->on("{$p}__credentials__lists")
                ->nullOnDelete();
        });

        Schema::table("{$p}__tools__lists", static function(Blueprint $table) use ($p): void {
            $table->unsignedBigInteger('credential_id')->nullable()->after('uuid');
            $table->foreign('credential_id')
                ->references('id')
                ->on("{$p}__credentials__lists")
                ->nullOnDelete();
        });

        // Restore data (first credential wins when multiple were attached)
        DB::table("{$p}__endpoints__credentials")
            ->orderBy('endpoint_id')
            ->get(['endpoint_id', 'credential_id'])
            ->groupBy('endpoint_id')
            ->each(static function($rows, int $endpointId) use ($p): void {
                DB::table("{$p}__endpoints__lists")
                    ->where('id', $endpointId)
                    ->update(['credential_id' => $rows->first()->credential_id]);
            });

        DB::table("{$p}__tools__credentials")
            ->orderBy('tool_id')
            ->get(['tool_id', 'credential_id'])
            ->groupBy('tool_id')
            ->each(static function($rows, int $toolId) use ($p): void {
                DB::table("{$p}__tools__lists")
                    ->where('id', $toolId)
                    ->update(['credential_id' => $rows->first()->credential_id]);
            });

        Schema::dropIfExists("{$p}__endpoints__credentials");
        Schema::dropIfExists("{$p}__tools__credentials");
    }
};
