<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\DTOs;

use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * Immutable payload DTO for inbound n8n deliveries.
 */
readonly class N8nPayload
{
    public function __construct(
        private array $data,
        private array $headers = [],
        private int|string|null $idempotencyKey = null,
        private ?int $executionId = null,
    ) {
    }

    public static function fromRequest(
        array $body,
        array $headers = [],
    ): self {
        // Normalize headers to lowercase for case-insensitive lookup
        $normalized  = array_change_key_case($headers, CASE_LOWER);
        $executionId = $normalized['x-n8n-execution-id'][0] ?? null;

        return new self(
            data: $body,
            headers: $normalized,
            idempotencyKey: $executionId,
            executionId: $executionId,
        );
    }

    // ── Data accessors ────────────────────────────────────────────────────────

    public function get(string $key, mixed $default = null): mixed
    {
        return data_get($this->data, $key, $default);
    }

    public function getString(string $key, string $default = ''): string
    {
        $value = $this->get($key, $default);

        return is_string($value) ? $value : (string) $value;
    }

    public function getInt(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function getFloat(string $key, float $default = 0.0): float
    {
        return (float) $this->get($key, $default);
    }

    public function getBool(string $key, bool $default = false): bool
    {
        $value = $this->get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @return array<int|string, mixed>
     */
    public function getArray(string $key, array $default = []): array
    {
        $value = $this->get($key, $default);

        return is_array($value) ? $value : $default;
    }

    public function getCarbon(string $key): ?CarbonInterface
    {
        $value = $this->get($key);

        if ($value === null) {
            return null;
        }

        try {
            return Carbon::parse($value);
        }
        catch (\Throwable) {
            return null;
        }
    }

    #[\NoDiscard]
    public function required(string $key): mixed
    {
        $value = $this->get($key);

        if ($value === null) {
            throw new \InvalidArgumentException("Required field [{$key}] missing in n8n payload.");
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return $this->get($key) !== null;
    }

    #[\NoDiscard]
    public function all(): array
    {
        return $this->data;
    }

    // ── Header accessors ─────────────────────────────────────────────────────

    public function header(string $name): ?string
    {
        $lower = strtolower($name);

        return $this->headers[$lower][0]
            ?? $this->headers[$name][0]
            ?? null;
    }

    public function idempotencyKey(): int|string|null
    {
        return $this->idempotencyKey;
    }

    public function executionId(): ?int
    {
        return $this->executionId;
    }

    // ── Mapping helper ────────────────────────────────────────────────────────

    /**
     * Map payload fields to a model-ready array using a field map.
     *
     * Example: ['payload.email' => 'email', 'payload.name' => 'full_name']
     *
     * @param  array<string, string>  $fieldMap
     * @return array<string, mixed>
     */
    public function mapTo(array $fieldMap): array
    {
        $result = [];

        foreach ($fieldMap as $from => $to) {
            $value = data_get($this->data, $from);

            if ($value !== null) {
                $result[$to] = $value;
            }
        }

        return $result;
    }
}
