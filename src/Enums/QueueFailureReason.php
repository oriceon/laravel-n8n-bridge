<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Enums;

/**
 * Classifies why a queue job failed.
 * Used for routing alerts, dashboards, and retry strategies.
 */
enum QueueFailureReason: string
{
    case ConnectionTimeout   = 'connection_timeout';
    case HttpError4xx        = 'http_4xx';           // client error (bad request, auth, etc.)
    case HttpError5xx        = 'http_5xx';           // server error (n8n down, etc.)
    case RateLimit           = 'rate_limit';         // 429 Too Many Requests
    case CircuitBreakerOpen  = 'circuit_breaker';    // the circuit breaker blocked it
    case PayloadTooLarge     = 'payload_too_large';  // n8n 413
    case WorkflowNotFound    = 'workflow_not_found'; // 404 on webhook
    case ValidationFailed    = 'validation';         // payload failed local validation
    case WorkerTimeout       = 'worker_timeout';     // worker took too long
    case UnknownException    = 'unknown';            // unexpected exception

    public function isRetryable(): bool
    {
        return match($this) {
            self::ConnectionTimeout,
            self::HttpError5xx,
            self::RateLimit,
            self::CircuitBreakerOpen,
            self::WorkerTimeout => true,

            self::HttpError4xx,
            self::PayloadTooLarge,
            self::WorkflowNotFound,
            self::ValidationFailed,
            self::UnknownException => false,
        };
    }

    public function suggestedDelaySeconds(): int
    {
        return match($this) {
            self::RateLimit          => 60,
            self::CircuitBreakerOpen => 60,
            self::HttpError5xx       => 30,
            self::ConnectionTimeout  => 15,
            self::WorkerTimeout      => 30,
            default                  => 10,
        };
    }

    public function label(): string
    {
        return match($this) {
            self::ConnectionTimeout  => 'Connection Timeout',
            self::HttpError4xx       => 'HTTP Client Error (4xx)',
            self::HttpError5xx       => 'HTTP Server Error (5xx)',
            self::RateLimit          => 'Rate Limited (429)',
            self::CircuitBreakerOpen => 'Circuit Breaker Open',
            self::PayloadTooLarge    => 'Payload Too Large (413)',
            self::WorkflowNotFound   => 'Workflow Not Found (404)',
            self::ValidationFailed   => 'Payload Validation Failed',
            self::WorkerTimeout      => 'Worker Timeout',
            self::UnknownException   => 'Unknown Exception',
        };
    }

    public static function fromHttpStatus(int $status): self
    {
        return match(true) {
            $status === 404                 => self::WorkflowNotFound,
            $status === 413                 => self::PayloadTooLarge,
            $status === 429                 => self::RateLimit,
            $status >= 400 && $status < 500 => self::HttpError4xx,
            $status >= 500                  => self::HttpError5xx,
            default                         => self::UnknownException,
        };
    }

    public static function fromException(\Throwable $e): self
    {
        $message = $e->getMessage()
            |> strtolower(...);

        return match(true) {
            str_contains($message, 'timeout') || str_contains($message, 'timed out')                    => self::ConnectionTimeout,
            str_contains($message, 'connection refused') || str_contains($message, 'could not connect') => self::ConnectionTimeout,
            str_contains($message, 'rate limit') || str_contains($message, '429')                       => self::RateLimit,
            default                                                                                     => self::UnknownException,
        };
    }
}
