<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Queue system tables.
 *
 * Same key strategy as core tables:
 *   id BIGINT auto-increment — primary key, all FK relations
 *   uuid CHAR(36) unique — public-facing identifier
 */
return new class() extends Migration {
    private string $tablePrefix;

    public function __construct()
    {
        $this->tablePrefix = config('n8n-bridge.table_prefix', 'n8n');
    }

    public function up(): void
    {
        // ── 1. Queue Batches ──────────────────────────────────────────────────
        Schema::create("{$this->tablePrefix}__queue__batches", static function(Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->string('name')->nullable();
            $table->string('description', 1000)->nullable();
            $table->unsignedTinyInteger('priority')->default(50); // QueueJobPriority (50=Normal)

            // Job counters — kept in sync by recalculate() after each state change
            $table->unsignedInteger('total_jobs')->default(0);
            $table->unsignedInteger('pending_jobs')->default(0);
            $table->unsignedInteger('done_jobs')->default(0);
            $table->unsignedInteger('failed_jobs')->default(0);
            $table->unsignedInteger('dead_jobs')->default(0);
            $table->unsignedInteger('cancelled_jobs')->default(0);

            // Batch lifecycle
            $table->boolean('cancelled')->default(false)->index();
            $table->json('options')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();
        });

        // ── 2. Queue Jobs ─────────────────────────────────────────────────────
        Schema::create("{$this->tablePrefix}__queue__jobs", function(Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('workflow_id')
                ->nullable()
                ->constrained("{$this->tablePrefix}__workflows__lists")
                ->nullOnDelete();

            $table->foreignId('batch_id')
                ->nullable()
                ->constrained("{$this->tablePrefix}__queue__batches")
                ->nullOnDelete();

            $table->string('status', 20)->default('pending')->index();  // QueueJobStatus
            $table->unsignedTinyInteger('priority')->default(50);   // QueueJobPriority (50=Normal)
            $table->json('payload');
            $table->json('context')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('max_attempts')->default(3);
            $table->unsignedInteger('timeout_seconds')->default(120);
            $table->string('n8n_instance', 60)->default('default');
            $table->string('queue_name', 50)->default('default');
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('n8n_execution_id')->nullable()->index();
            $table->json('n8n_response')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->string('last_failure_reason')->nullable();
            $table->string('last_error_message', 1000)->nullable();
            $table->string('last_error_class')->nullable();
            $table->string('worker_id')->nullable()->index();
            $table->timestamp('reserved_until')->nullable();
            $table->timestamp('available_at')->nullable()->index();
            $table->timestamp('reserved_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();

            $table->timestamps();

            $table->index(['workflow_id', 'status']);
            $table->index(['status', 'priority', 'available_at']);
        });

        // ── 3. Queue Failures ─────────────────────────────────────────────────
        // Append-only audit trail — one row per failed attempt.
        // UPDATED_AT is intentionally unused (model sets UPDATED_AT = null).
        Schema::create("{$this->tablePrefix}__queue__failures", function(Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('job_id')
                ->constrained("{$this->tablePrefix}__queue__jobs")
                ->cascadeOnDelete();

            // Denormalised for fast DLQ queries without a JOIN
            $table->foreignId('workflow_id')
                ->nullable()
                ->constrained("{$this->tablePrefix}__workflows__lists")
                ->nullOnDelete();

            $table->string('reason'); // QueueFailureReason
            $table->unsignedTinyInteger('attempt_number')->default(1);

            $table->text('error_message')->nullable();
            $table->string('error_class')->nullable();
            $table->text('stack_trace')->nullable();

            $table->unsignedSmallInteger('http_status')->nullable();
            $table->json('http_response')->nullable();

            $table->string('worker_id')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();

            $table->json('payload_snapshot')->nullable();
            $table->json('context')->nullable();

            $table->boolean('was_retried')->default(false);
            $table->boolean('was_replayed')->default(false);

            $table->timestamps();

            $table->index(['job_id', 'attempt_number']);
            $table->index(['workflow_id', 'reason']);
            $table->index('was_replayed');
        });

        // ── 4. Queue Checkpoints ──────────────────────────────────────────────
        Schema::create("{$this->tablePrefix}__queue__checkpoints", function(Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('job_id')
                ->constrained("{$this->tablePrefix}__queue__jobs")
                ->cascadeOnDelete();

            $table->string('node_name', 128);
            $table->string('node_label', 128)->nullable();
            $table->string('status', 20); // CheckpointStatus
            $table->text('message')->nullable();
            $table->json('data')->nullable();
            $table->text('error_message')->nullable();
            $table->unsignedTinyInteger('progress_percent')->nullable();
            $table->unsignedInteger('sequence')->default(0);

            $table->timestamps();

            $table->index(['job_id', 'sequence']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("{$this->tablePrefix}__queue__checkpoints");
        Schema::dropIfExists("{$this->tablePrefix}__queue__failures");
        Schema::dropIfExists("{$this->tablePrefix}__queue__jobs");
        Schema::dropIfExists("{$this->tablePrefix}__queue__batches");
    }
};
