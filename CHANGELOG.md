![Laravel N8N Bridge](docs/images/banner.png)

# Changelog

All notable changes to `oriceon/laravel-n8n-bridge` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

---

## 1.0.0 - 2026-04-02

### Added

**Core bridge**
- Outbound rate limiting — configurable per-minute cap on Laravel→n8n requests, global (`N8N_BRIDGE_OUTBOUND_RATE_LIMIT`) with per-workflow override (`rate_limit` column); async path re-dispatches with delay, sync path marks delivery failed, queue worker releases job back to pending
- `N8nWorkflow::effectiveRateLimit(): int` — resolves per-workflow or global config value
- `OutboundRateLimiter` service — final class, Laravel `RateLimiter` backed, 60-second decay window per workflow
- Migration `000004` — adds `rate_limit` (nullable SMALLINT UNSIGNED) to `workflows__lists`
- DB-driven workflow management with bidirectional n8n sync
- Inbound webhook receiver with 6-layer security pipeline (rate limit, API key, IP whitelist, HMAC, idempotency, payload store)
- `N8nCredential` entity — one key authenticates all `/n8n/*` routes (inbound, tools, queue progress)
- Rotatable API keys per webhook with configurable grace period (zero-downtime rotation)
- Per-workflow circuit breaker (Closed → Open → HalfOpen) with automatic state transitions
- Outbound dispatcher with four retry strategies: Exponential, Linear, Fixed, Fibonacci
- Per-workflow outbound authentication: `none` / `header_token` / `bearer` / `hmac_sha256`
  - `auth_type` + `auth_key` (AES-256 encrypted via Laravel `encrypted` cast)
  - `WebhookAuthService::buildHeaders($workflow, $body)` — single place for all auth logic
  - `WebhookAuthService::generateKey()` — 64-char hex (256 bits of entropy)
  - HMAC-SHA256 mode signs `{timestamp}.{sha256(body)}` for replay protection (`X-N8N-Timestamp` + `X-N8N-Signature`)
  - `N8nWorkflow::hasWebhookAuth()` — helper to check if auth is configured
  - `N8nWorkflowFactory::withAuth(WebhookAuthType $type)` — test/seeder shorthand
  - Both `N8nOutboundDispatcher` and `QueueWorker` use `WebhookAuthService` transparently
  - Migration `000003` adds the two new columns (replaces old `webhook_secret`)
- Tool system: typed Laravel endpoints exposed as n8n HTTP nodes with auto-generated OpenAPI 3 schema
- Daily statistics aggregation with chart-ready output (`labels`, `success`, `failed`, `success_rate`)
- Multi-channel alert notifications: Mail, Slack, Discord, Microsoft Teams, generic Webhook
- Idempotency via `X-N8N-Execution-Id` header — duplicate requests are safely deduplicated

**Queue system**
- DB queue (`n8n__queue__jobs`) with 5 priority levels (Critical/High/Normal/Low/Bulk)
- `QueueDispatcher` fluent API: payload, priority, delay, max attempts, timeout, idempotent dispatch
- Batch support: `dispatchMany()` and `dispatchFromQuery()` (memory-efficient via `chunkById`)
- `SELECT FOR UPDATE SKIP LOCKED` worker claiming — safe for multiple concurrent workers
- Worker lease system with stuck-job recovery (`--recover` flag)
- Live progress tracking via `POST /n8n/queue/progress/{jobUuid}`
- `N8nQueueJobProgressUpdatedEvent` event — implements `ShouldBroadcast` for real-time frontend updates
- Rolling EMA (exponential moving average) of job durations per workflow for estimated completion time
- `__done__` and `__failed__` special checkpoint nodes for terminal status transitions
- Dead Letter Queue — exhausted jobs move to `status=dead` with full failure history
- Checkpoint auto-delete on success (configurable via `N8N_BRIDGE_QUEUE_DELETE_CHECKPOINTS`)

**Artisan commands**
- `n8n:credential:create` / `n8n:credential:rotate`
- `n8n:endpoint:create` / `n8n:endpoint:list` / `n8n:endpoint:rotate`
- `n8n:workflows:sync`
- `n8n:test-inbound` (with `--dry-run`)
- `n8n:dlq:list` / `n8n:dlq:retry`
- `n8n:queue:work` / `n8n:queue:status` / `n8n:queue:retry` / `n8n:queue:cancel` / `n8n:queue:prune`
- `n8n:stats` / `n8n:health`
- `make:n8n-tool` generator with stub

**Developer experience**
- 832 Pest tests (Unit + Feature + Architecture) — all passing
- PHP 8.5 backed string enums, `readonly` DTOs, `#[ObservedBy]` Laravel 13 attribute
- Configurable table prefix (`N8N_BRIDGE_TABLE_PREFIX`)
- Multi-instance n8n support (per workflow)
- `TriggersN8nOnEvents` Eloquent trait for automatic outbound triggers
- `N8nApiKey` with SHA-256 hashing, constant-time comparison, visible prefix (`n8br_sk_`)
- PHPStan level 8 clean

### Fixed
- `N8nQueueCheckpoint` `ModelNotFoundException` when `delete_checkpoints_on_success=true`: event now fires before `handleTerminalNode()` to avoid `SerializesModels` deserializing a deleted checkpoint
- `resolveInstanceConfig()` no longer falls back to the `default` instance for misconfigured workflow instances
- `N8nQueueJobProgressUpdatedEvent` `ShouldBroadcast` + `SerializesModels` interaction with sync queue driver
- `ProcessN8nInboundJob` constructor accepting `int` primary keys (not only `string`)
- `N8nAlertNotification::deliveryDead()` accepting `int` delivery IDs
- `N8nAlertNotifiable::getKey()` required by Laravel `NotificationFake` for test assertions
- `N8nQueueCheckpoint::scopeForJob()` and `timelineForJob()` accepting `int|string` job IDs
- `N8nQueueJob` route model binding uses `uuid` column — progress URLs expose UUID not integer PK
- `NotificationChannel::Mail` config key corrected to `n8n-bridge.notifications.mail_to`
- `N8nToolRequest` made `readonly` to satisfy architecture test
- `N8nToolResponse` made `readonly` to satisfy architecture test
- `N8nCredential::newFactory()` added for correct factory namespace resolution
- `EndpointCreateCommand` (`n8n:endpoint:create`) now auto-creates a dedicated `N8nCredential` and generates an API key — previously passed `endpoint->id` as `credential_id` FK causing a constraint violation
- `EndpointRotateCommand` (`n8n:endpoint:rotate`) now validates `credential_id` presence before rotating, and queries API keys via the correct `credential_id` FK
- `DlqRetryCommand` (`n8n:dlq:retry`) now skips deliveries with no attached endpoint (null `endpoint_id`) instead of triggering a TypeError; lookup changed from integer PK to UUID
- `DlqListCommand` (`n8n:dlq:list`) fixed `substr($d->id, ...)` TypeError — `id` is BIGINT; now uses `$d->uuid`
- `WebhookListCommand` (`n8n:credential:list`) fixed `substr($w->id, ...)` TypeError — now uses `$w->uuid`
- `WebhookRotateCommand` (`n8n:credential:rotate`) fixed `Model::find($uuid)` returning null — now uses `where('uuid', $id)->first()`
- `WebhookAttachCommand` (`n8n:credential:attach`) fixed `N8nCredential::find($uuid)` PK lookup — now queries by UUID
- `QueueCancelCommand` (`n8n:queue:cancel`) fixed UUID-based lookups for both job and batch; fixed `forBatch()` scope receiving a UUID string instead of integer PK
- `QueueRetryCommand` (`n8n:queue:retry`) fixed `N8nQueueJob::find($uuid)` PK lookup — now queries by UUID
- `QueueWorker` now eager-loads the `workflow` relation in `claim()` to prevent N+1 queries and null-deref when accessing `$job->workflow->name`
- Migration: `endpoints__lists.credential_id` and `tools__lists.credential_id` changed to nullable — allows creating endpoints/tools before attaching a credential
- Model `@property` docblocks: all FK integer columns (`workflow_id`, `endpoint_id`, `batch_id`, `job_id`, `credential_id`) corrected from `string` to `int|null` across 9 model files
