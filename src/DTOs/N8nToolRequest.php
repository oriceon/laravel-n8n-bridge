<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\DTOs;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Typed request wrapper for all /n8n/tools/{name} calls.
 *
 * Works for every HTTP method — GET, POST, PUT, PATCH, DELETE.
 *
 * For GET requests, data comes from query parameters:
 *   GET /n8n/tools/invoices?filter[status]=paid&per_page=50&sort=-created_at
 *
 * For POST/PATCH/PUT, data comes from the JSON body:
 *   POST /n8n/tools/send-invoice { "invoice_id": 42, "email": "x@y.com" }
 *
 * The helpers work the same regardless of method — they look at both
 * query params and body, so your handler code stays clean.
 */
final readonly class N8nToolRequest
{
    /**
     * @param Request $request
     * @param string $toolName
     * @param string|null $callerWorkflowId
     */
    public function __construct(
        private Request $request,
        private string $toolName = '',
        private ?string $callerWorkflowId = null,
    ) {
    }

    // ── Direct input access ───────────────────────────────────────────────────

    /**
     * Get any input value (body or query params).
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public function get(string $key, mixed $default = null): mixed
    {
        return $this->request->input($key, $this->request->query($key, $default));
    }

    /**
     * Get a required value — throws \InvalidArgumentException if missing.
     *
     * @param string $key
     * @return mixed
     */
    public function required(string $key): mixed
    {
        $value = $this->get($key);

        if ($value === null || $value === '') {
            throw new \InvalidArgumentException("Required field [{$key}] is missing from tool request [{$this->toolName}].");
        }

        return $value;
    }

    /**
     * @param string $key
     * @param string $default
     * @return string
     */
    public function getString(string $key, string $default = ''): string
    {
        return (string) ($this->get($key) ?? $default);
    }

    /**
     * @param string $key
     * @param int $default
     * @return int
     */
    public function getInt(string $key, int $default = 0): int
    {
        return (int) ($this->get($key) ?? $default);
    }

    /**
     * @param string $key
     * @param float $default
     * @return float
     */
    public function getFloat(string $key, float $default = 0.0): float
    {
        return (float) ($this->get($key) ?? $default);
    }

    /**
     * @param string $key
     * @param bool $default
     * @return bool
     */
    public function getBool(string $key, bool $default = false): bool
    {
        $val = $this->get($key);

        if ($val === null) {
            return $default;
        }

        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param string $key
     * @param array $default
     * @return array
     */
    public function getArray(string $key, array $default = []): array
    {
        $val = $this->get($key);

        if ($val === null) {
            return $default;
        }

        return is_array($val) ? $val : [$val];
    }

    /**
     * @param string $key
     * @return Carbon|null
     */
    public function getCarbon(string $key): ?Carbon
    {
        $val = $this->get($key);

        return $val ? Carbon::parse($val) : null;
    }

    /**
     * @param string $key
     * @return bool
     */
    public function has(string $key): bool
    {
        return $this->request->has($key) || $this->request->query($key) !== null;
    }

    public function all(): array
    {
        // Merge query params + body (body takes precedence)
        return array_merge($this->request->query(), $this->request->all());
    }

    // ── REST-style query helpers (useful for GET tools) ───────────────────────

    /**
     * GET ?filter[status]=paid&filter[customer_id]=42
     * → ['status' => 'paid', 'customer_id' => '42']
     */
    public function filters(): array
    {
        return (array) $this->request->query('filter', []);
    }

    /**
     * Get a specific filter value.
     *
     * @param string $key
     * @param mixed|null $default
     * @return mixed
     */
    public function filter(string $key, mixed $default = null): mixed
    {
        return data_get($this->filters(), $key, $default);
    }

    /**
     * @param string $key
     * @param int $default
     * @return int
     */
    public function filterInt(string $key, int $default = 0): int
    {
        return (int) ($this->filter($key) ?? $default);
    }

    /**
     * @param string $key
     * @param bool $default
     * @return bool
     */
    public function filterBool(string $key, bool $default = false): bool
    {
        $val = $this->filter($key);

        if ($val === null) {
            return $default;
        }

        return filter_var($val, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * @param string $key
     * @return Carbon|null
     */
    public function filterDate(string $key): ?Carbon
    {
        $val = $this->filter($key);

        return $val ? Carbon::parse($val) : null;
    }

    /**
     * GET ?filter[status]=paid,draft → ['paid', 'draft']
     *
     * @param string $key
     * @param string $delimiter
     * @return array
     */
    public function filterArray(string $key, string $delimiter = ','): array
    {
        $val = $this->filter($key);

        if ($val === null) {
            return [];
        }

        return is_array($val) ? $val : array_filter(explode($delimiter, (string) $val));
    }

    /**
     * GET ?search=john doe
     */
    public function search(): ?string
    {
        $val = $this->request->query('search', '');

        return $val !== '' ? (string) $val : null;
    }

    /**
     * GET ?sort=-created_at,name
     * → [['field'=>'created_at','direction'=>'desc'], ['field'=>'name','direction'=>'asc']]
     */
    public function sorts(): array
    {
        $sort = $this->request->query('sort', '');

        if ( ! $sort) {
            return [];
        }

        return collect(explode(',', $sort))
            ->filter()
            ->map(fn(string $part) => [
                'field'     => ltrim(trim($part), '-'),
                'direction' => str_starts_with(trim($part), '-') ? 'desc' : 'asc',
            ])
            ->values()
            ->all();
    }

    /**
     * Apply allowed sorts to an Eloquent query.
     *
     * @param Builder $query
     * @param array $allowedSorts
     * @return Builder
     */
    public function applySorts(
        Builder $query,
        array $allowedSorts = [],
    ): Builder {
        foreach ($this->sorts() as ['field' => $field, 'direction' => $dir]) {
            if (empty($allowedSorts) || in_array($field, $allowedSorts, true)) {
                $query->orderBy($field, $dir);
            }
        }

        return $query;
    }

    /**
     * GET ?per_page=50
     *
     * @param int $default
     * @param int $max
     * @return int
     */
    public function perPage(int $default = 15, int $max = 100): int
    {
        return min(max(1, (int) $this->request->query('per_page', $default)), $max);
    }

    /**
     * GET ?page=2
     */
    public function page(): int
    {
        return max(1, (int) $this->request->query('page', 1));
    }

    /**
     * GET ?fields=id,name,status
     */
    public function fields(): array
    {
        $val = $this->request->query('fields', '');

        return $val ? array_filter(explode(',', $val)) : [];
    }

    /**
     * GET ?include=customer,items
     */
    public function includes(): array
    {
        $val = $this->request->query('include', '');

        return $val ? array_filter(explode(',', $val)) : [];
    }

    /**
     * @param string $relation
     * @return bool
     */
    public function wants(string $relation): bool
    {
        return in_array($relation, $this->includes(), true);
    }

    // ── Meta ──────────────────────────────────────────────────────────────────

    public function toolName(): string
    {
        return $this->toolName;
    }

    public function callerWorkflowId(): ?string
    {
        return $this->callerWorkflowId;
    }

    public function method(): string
    {
        return strtoupper($this->request->method());
    }

    public function isGet(): bool
    {
        return $this->method() === 'GET';
    }

    public function isPost(): bool
    {
        return $this->method() === 'POST';
    }

    public function isPatch(): bool
    {
        return $this->method() === 'PATCH';
    }

    public function isPut(): bool
    {
        return $this->method() === 'PUT';
    }

    public function isDelete(): bool
    {
        return $this->method() === 'DELETE';
    }

    public function rawRequest(): Request
    {
        return $this->request;
    }
}
