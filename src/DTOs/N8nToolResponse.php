<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\DTOs;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

/**
 * Standardized response for all /n8n/tools/{name} calls.
 *
 * PHP 8.5: Uses clone($this, [...]) for immutable withMeta() copies.
 * All factory methods are marked #[\NoDiscard] — the response must
 * be returned, not silently discarded.
 *
 * Response shapes:
 *   item()       → single record    { "data": { ...fields } }
 *   collection() → list of records  { "data": [ {...}, {...} ] }
 *   paginated()  → list + meta      { "data": [...], "meta": { "total": 100, ... } }
 *   success()    → action result    { "data": { "sent": true } }
 *   error()      → failure          { "error": "message" }
 *   notFound()   → 404              { "error": "..." }
 */
final readonly class N8nToolResponse
{
    /**
     * @param mixed $data
     * @param array $meta
     * @param int $httpStatus
     * @param bool $isError
     */
    private function __construct(
        private mixed $data,
        private array $meta      = [],
        private int $httpStatus = 200,
        private bool $isError   = false,
    ) {
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    /**
     * @param mixed $item
     * @param \Closure|null $transform
     * @return self
     */
    #[\NoDiscard]
    public static function item(mixed $item, ?\Closure $transform = null): self
    {
        $data = $transform ? $transform($item) : (
            (is_object($item) && method_exists($item, 'toArray')) ? $item->toArray() : (array) $item
        );

        return new self(data: $data);
    }

    /**
     * @param iterable $items
     * @param \Closure|null $transform
     * @return self
     */
    #[\NoDiscard]
    public static function collection(iterable $items, ?\Closure $transform = null): self
    {
        $data = collect($items)
            ->map(
                static fn($item) => $transform
                ? $transform($item)
                : ((is_object($item) && method_exists($item, 'toArray')) ? $item->toArray() : (array) $item)
            )
            ->values()
            ->all();

        return new self(data: $data);
    }

    /**
     * @param LengthAwarePaginator $paginator
     * @param \Closure|null $transform
     * @return self
     */
    #[\NoDiscard]
    public static function paginated(LengthAwarePaginator $paginator, ?\Closure $transform = null): self
    {
        $data = collect($paginator->items())
            ->map(
                static fn($item) => $transform
                ? $transform($item)
                : ((is_object($item) && method_exists($item, 'toArray')) ? $item->toArray() : (array) $item)
            )
            ->values()
            ->all();

        return new self(
            data: $data,
            meta: [
                'total'        => $paginator->total(),
                'per_page'     => $paginator->perPage(),
                'current_page' => $paginator->currentPage(),
                'last_page'    => $paginator->lastPage(),
                'from'         => $paginator->firstItem(),
                'to'           => $paginator->lastItem(),
                'has_more'     => $paginator->hasMorePages(),
            ],
        );
    }

    /**
     * @param array $data
     * @param string|null $message
     * @return self
     */
    #[\NoDiscard]
    public static function success(array $data = [], ?string $message = null): self
    {
        if ($message !== null) {
            $data = ['message' => $message, ...$data];
        }

        return new self(data: $data);
    }

    /**
     * @param array $meta
     * @return self
     */
    #[\NoDiscard]
    public static function empty(array $meta = []): self
    {
        return new self(data: [], meta: $meta);
    }

    /**
     * @param string $message
     * @param int $status
     * @param array $context
     * @return self
     */
    #[\NoDiscard]
    public static function error(string $message, int $status = 400, array $context = []): self
    {
        $data = $context ? ['error' => $message, ...$context] : $message;

        return new self(data: $data, httpStatus: $status, isError: true);
    }

    /**
     * @param string $message
     * @return self
     */
    #[\NoDiscard]
    public static function notFound(string $message = 'Not found.'): self
    {
        return self::error($message, 404);
    }

    /**
     * @param string $message
     * @return self
     */
    #[\NoDiscard]
    public static function unauthorized(string $message = 'Unauthorized.'): self
    {
        return self::error($message, 401);
    }

    /**
     * Returns a new instance with extra metadata merged.
     * PHP 8.5: uses clone($this, [...]) for immutable copy.
     *
     * @param array $extra
     * @return N8nToolResponse
     */
    #[\NoDiscard]
    public function withMeta(array $extra): self
    {
        return clone($this, [
            'meta' => array_merge($this->meta, $extra),
        ]);
    }

    // ── Rendering ─────────────────────────────────────────────────────────────

    public function toJsonResponse(): JsonResponse
    {
        if ($this->isError) {
            $payload = is_string($this->data)
                ? ['error' => $this->data]
                : $this->data;

            return response()->json($payload, $this->httpStatus);
        }

        $payload = ['data' => $this->data];

        if ( ! empty($this->meta)) {
            $payload['meta'] = $this->meta;
        }

        return response()->json($payload, $this->httpStatus);
    }

    /** @deprecated Use toJsonResponse() instead. */
    #[\Deprecated(message: 'Use toJsonResponse() instead.', since: '1.0')]
    public function toArray(): array
    {
        if ($this->isError) {
            return [
                'status' => 'error',
                'error'  => is_string($this->data) ? $this->data : ($this->data['error'] ?? 'Error'),
                'data'   => is_array($this->data) ? $this->data : [],
            ];
        }

        return [
            'status' => 'success',
            'data'   => $this->data,
            'meta'   => $this->meta ?: null,
        ];
    }

    // ── Accessors ─────────────────────────────────────────────────────────────

    public function isSuccess(): bool
    {
        return ! $this->isError;
    }

    public function isError(): bool
    {
        return $this->isError;
    }

    public function getData(): mixed
    {
        return $this->data;
    }

    public function getMeta(): array
    {
        return $this->meta;
    }

    public function getStatus(): int
    {
        return $this->httpStatus;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
