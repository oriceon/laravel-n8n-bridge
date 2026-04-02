<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\DTOs;

use Carbon\Carbon;
use Carbon\CarbonInterface;
use Oriceon\N8nBridge\Enums\DeliveryStatus;

/**
 * Immutable DTO for n8n execution results.
 *
 * PHP 8.5: readonly class with clone($this, [...]) wither methods.
 * The "with-er" pattern is now idiomatic — no more custom copy constructors.
 */
readonly class N8nExecutionResult
{
    /**
     * @param string $id
     * @param string $workflowId
     * @param DeliveryStatus $status
     * @param CarbonInterface|null $startedAt
     * @param CarbonInterface|null $finishedAt
     * @param int $durationMs
     * @param array|null $outputData
     * @param bool $finished
     */
    public function __construct(
        public string $id,
        public string $workflowId,
        public DeliveryStatus $status,
        public ?CarbonInterface $startedAt,
        public ?CarbonInterface $finishedAt,
        public int $durationMs,
        public ?array $outputData,
        public bool $finished,
    ) {
    }

    // ── Factory ───────────────────────────────────────────────────────────────

    /**
     * @param array $data
     * @return self
     */
    public static function fromApiResponse(array $data): self
    {
        // PHP 8.5 pipe operator: parse timestamps as a readable pipeline
        $startedAt  = isset($data['startedAt']) ? Carbon::parse($data['startedAt']) : null;
        $finishedAt = isset($data['stoppedAt']) ? Carbon::parse($data['stoppedAt']) : null;

        $durationMs = ($startedAt && $finishedAt)
            ? (int) $startedAt->diffInMilliseconds($finishedAt)
            : 0;

        $status = match($data['status'] ?? 'unknown') {
            'success' => DeliveryStatus::Done,
            'running', 'waiting' => DeliveryStatus::Processing,
            default => DeliveryStatus::Failed,
        };

        return new self(
            id:         $data['id'],
            workflowId: $data['workflowId'],
            status:     $status,
            startedAt:  $startedAt,
            finishedAt: $finishedAt,
            durationMs: $durationMs,
            outputData: $data['data'] ?? null,
            finished:   (bool) ($data['finished'] ?? false),
        );
    }

    // ── PHP 8.5 "clone with" wither pattern ──────────────────────────────────

    /**
     * Returns a new instance with the execution marked as finished.
     * PHP 8.5: clone($this, [...]) — clean immutable copy with property override.
     *
     * @param DeliveryStatus $status
     * @return N8nExecutionResult
     */
    #[\NoDiscard]
    public function withStatus(DeliveryStatus $status): self
    {
        return clone($this, ['status' => $status]);
    }

    /**
     * @param array $data
     * @return $this
     */
    #[\NoDiscard]
    public function withOutputData(array $data): self
    {
        return clone($this, ['outputData' => $data, 'finished' => true]);
    }

    // ── Predicates ────────────────────────────────────────────────────────────

    public function isSuccess(): bool
    {
        return $this->status === DeliveryStatus::Done;
    }

    public function isFailed(): bool
    {
        return $this->status === DeliveryStatus::Failed;
    }

    public function isRunning(): bool
    {
        return $this->status === DeliveryStatus::Processing;
    }
}
