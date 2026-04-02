<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Core bridge tables.
 *
 * Key strategy:
 *   id BIGINT auto-increment — primary key, all FK relations, all JOINs (fast)
 *   uuid CHAR(36) unique — public-facing identifier in URLs (never used in FK)
 *
 * This pattern gives INT performance for DB operations while keeping UUIDs
 * safe for external exposure without leaking row counts or insert order.
 */
return new class() extends Migration {
    private string $tablePrefix;

    public function __construct()
    {
        $this->tablePrefix = config('n8n-bridge.table_prefix', 'n8n');
    }

    public function up(): void
    {
        // ── 1. Credentials ────────────────────────────────────────────────────
        // One credential bundle per n8n instance. Holds rotatable API keys
        // that authenticate inbound requests (from n8n to Laravel).
        Schema::create("{$this->tablePrefix}__credentials__lists", static function(Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->string('name');
            $table->string('description', 1000)->nullable();
            $table->string('n8n_instance', 60)->default('default');
            $table->json('allowed_ips')->nullable();
            $table->boolean('is_active')->default(true)->index();
            $table->timestamps();
        });

        // ── 2. Workflows ──────────────────────────────────────────────────────
        Schema::create("{$this->tablePrefix}__workflows__lists", static function(Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->char('n8n_id', 36)->nullable()->index();
            $table->string('n8n_instance', 60)->default('default')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('webhook_path', 64)->nullable();
            $table->string('webhook_mode', 20)->default('auto')->comment('auto|production|test — controls /webhook vs /webhook-test');
            $table->string('auth_type', 30)->default('none'); // WebhookAuthType
            $table->text('auth_key')->nullable(); // AES-256 encrypted
            $table->string('http_method', 10)->default('POST'); // HttpMethod
            $table->unsignedSmallInteger('rate_limit')->nullable()->comment('Max outbound requests/min to n8n. null = global config default. 0 = unlimited.');
            $table->json('tags')->nullable();
            $table->json('nodes')->nullable();
            $table->json('meta')->nullable();

            // Rolling average of successful execution durations (milliseconds).
            // NULL means "not enough data yet" — at least 1 successful job required.
            $table->unsignedInteger('estimated_duration_ms')->nullable();

            // How many successful executions are in the current rolling average.
            // Capped at the configured sample size (default 50).
            $table->unsignedSmallInteger('estimated_sample_count')->default(0);

            // Timestamp of last estimation update — for debugging.
            $table->timestamp('estimated_updated_at')->nullable();

            // Multi-tenancy
            $table->nullableMorphs('owner');

            $table->boolean('is_active')->default(true)->index();
            $table->timestamp('last_synced_at')->nullable();
            $table->softDeletes();
            $table->timestamps();

            $table->index(['n8n_instance', 'is_active']);
        });

        // ── 3. Inbound endpoints ──────────────────────────────────────────────
        Schema::create("{$this->tablePrefix}__endpoints__lists", function(Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('credential_id')
                ->nullable()
                ->constrained("{$this->tablePrefix}__credentials__lists")
                ->nullOnDelete();

            $table->string('slug')->unique();
            $table->string('auth_type', 10)->default('api_key'); // AuthType
            $table->string('handler_class');
            $table->string('queue', 50)->default('default');
            $table->json('allowed_ips')->nullable();
            $table->boolean('verify_hmac')->default(false);
            $table->string('hmac_secret')->nullable();
            $table->unsignedSmallInteger('rate_limit')->default(60);
            $table->boolean('store_payload')->default(true);
            $table->string('retry_strategy', 20)->default('exponential'); // RetryStrategy
            $table->unsignedTinyInteger('max_attempts')->default(3);

            // Multi-tenancy — optional owner scoping (same pattern as workflows)
            $table->nullableMorphs('owner');

            $table->boolean('is_active')->default(true);
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['slug', 'is_active']);
        });

        // ── 4. API Keys ───────────────────────────────────────────────────────
        Schema::create("{$this->tablePrefix}__api_keys__lists", function(Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('credential_id')
                ->constrained("{$this->tablePrefix}__credentials__lists")
                ->cascadeOnDelete();

            // Optional per-endpoint scoping — null means credential-level key (shared by all endpoints)
            $table->foreignId('endpoint_id')
                ->nullable()
                ->constrained("{$this->tablePrefix}__endpoints__lists")
                ->nullOnDelete();

            $table->string('key_hash');
            $table->string('key_prefix', 16);
            $table->string('status', 20)->default('active'); // ApiKeyStatus
            $table->timestamp('grace_until')->nullable();
            $table->string('created_by')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('use_count')->default(0);
            $table->timestamp('revoked_at')->nullable();

            $table->timestamps();

            $table->index(['key_hash', 'status']);
            $table->index(['credential_id', 'status']);
            $table->index(['endpoint_id', 'status']);
        });

        // ── 5. Deliveries ─────────────────────────────────────────────────────
        Schema::create("{$this->tablePrefix}__deliveries__lists", function(Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('workflow_id')
                ->constrained("{$this->tablePrefix}__workflows__lists")
                ->cascadeOnDelete();

            $table->foreignId('endpoint_id')
                ->nullable()
                ->constrained("{$this->tablePrefix}__endpoints__lists")
                ->nullOnDelete();

            $table->string('status', 20)->default('pending');       // DeliveryStatus
            $table->string('direction', 10)->default('outbound');   // DeliveryDirection
            $table->json('payload')->nullable();
            $table->json('response')->nullable();
            $table->text('error_message')->nullable();
            $table->string('error_class')->nullable();
            $table->string('idempotency_key')->nullable()->unique();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedInteger('duration_ms')->nullable();
            $table->unsignedBigInteger('n8n_execution_id')->nullable()->index();
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->timestamp('next_attempt_at')->nullable();
            $table->timestamp('processed_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['workflow_id', 'status']);
            $table->index(['status', 'next_attempt_at']);
            $table->index(['direction', 'status']);
        });

        // ── 6. Circuit breakers ───────────────────────────────────────────────
        Schema::create("{$this->tablePrefix}__circuit_breakers__lists", function(Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('workflow_id')
                ->unique()
                ->constrained("{$this->tablePrefix}__workflows__lists")
                ->cascadeOnDelete();

            $table->string('state', 20)->default('closed'); // CircuitBreakerState
            $table->unsignedInteger('failure_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->timestamp('last_failure_at')->nullable();
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('half_open_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('next_attempt_at')->nullable();

            $table->timestamps();
        });

        // ── 7. Stats ──────────────────────────────────────────────────────────
        Schema::create("{$this->tablePrefix}__stats__lists", function(Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('workflow_id')
                ->constrained("{$this->tablePrefix}__workflows__lists")
                ->cascadeOnDelete();

            $table->foreignId('endpoint_id')
                ->nullable()
                ->constrained("{$this->tablePrefix}__endpoints__lists")
                ->nullOnDelete();

            $table->string('direction', 10)->default('outbound'); // DeliveryDirection
            $table->string('period', 10)->default('daily');       // StatPeriod
            $table->date('period_date');

            // Counters
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('success_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);
            $table->unsignedInteger('dlq_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);

            // Duration metrics
            $table->unsignedInteger('avg_duration_ms')->default(0);
            $table->unsignedInteger('p95_duration_ms')->default(0);

            // Bandwidth metrics (bytes)
            $table->unsignedBigInteger('total_bytes_in')->default(0);
            $table->unsignedBigInteger('total_bytes_out')->default(0);

            $table->timestamps();

            // One row per workflow/endpoint/direction/period/date combination
            $table->unique(
                ['workflow_id', 'endpoint_id', 'direction', 'period', 'period_date'],
                "{$this->tablePrefix}_stats_wedpd_idx"
            );
            $table->index(['direction', 'period', 'period_date']);
        });

        // ── 8. Tools ──────────────────────────────────────────────────────────
        Schema::create("{$this->tablePrefix}__tools__lists", function(Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('credential_id')
                ->nullable()
                ->constrained("{$this->tablePrefix}__credentials__lists")
                ->nullOnDelete();

            $table->string('name')->unique();
            $table->string('label');
            $table->string('description', 1000)->nullable();
            $table->string('category')->nullable();
            $table->string('handler_class');

            // HTTP methods this tool accepts (null = POST only, backward compat)
            $table->json('allowed_methods')->nullable();
            $table->json('allowed_ips')->nullable();
            $table->unsignedSmallInteger('rate_limit')->default(120);

            $table->json('request_schema')->nullable();
            $table->json('response_schema')->nullable();
            $table->json('examples')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['category', 'is_active']);
            $table->index(['name', 'is_active']);
        });

        // ── 9. Event Subscriptions ────────────────────────────────────────────
        // Subscribes Laravel events / Eloquent events to n8n workflow triggers.
        Schema::create("{$this->tablePrefix}__event_subscriptions__lists", function(Blueprint $table): void {
            $table->id();
            $table->uuid()->unique();

            $table->foreignId('workflow_id')
                ->constrained("{$this->tablePrefix}__workflows__lists")
                ->cascadeOnDelete();

            $table->string('event_class')->index();
            $table->string('eloquent_model')->nullable()->index();
            $table->string('eloquent_event')->nullable(); // EloquentEvent
            $table->json('conditions')->nullable();
            $table->boolean('queue_dispatch')->default(true);
            $table->string('queue_name', 50)->default('default');
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->index(['event_class', 'is_active'], "{$this->tablePrefix}_ev_subs_event_active_idx");
            $table->index(['eloquent_model', 'eloquent_event', 'is_active'], "{$this->tablePrefix}_ev_subs_model_event_active_idx");
        });
    }

    public function down(): void
    {
        Schema::dropIfExists("{$this->tablePrefix}__event_subscriptions__lists");
        Schema::dropIfExists("{$this->tablePrefix}__tools__lists");
        Schema::dropIfExists("{$this->tablePrefix}__stats__lists");
        Schema::dropIfExists("{$this->tablePrefix}__circuit_breakers__lists");
        Schema::dropIfExists("{$this->tablePrefix}__deliveries__lists");
        Schema::dropIfExists("{$this->tablePrefix}__api_keys__lists");
        Schema::dropIfExists("{$this->tablePrefix}__endpoints__lists");
        Schema::dropIfExists("{$this->tablePrefix}__workflows__lists");
        Schema::dropIfExists("{$this->tablePrefix}__credentials__lists");
    }
};
