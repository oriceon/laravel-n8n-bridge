<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Enums;

use Random\RandomException;

enum RetryStrategy: string
{
    case Exponential = 'exponential';
    case Linear      = 'linear';
    case Fixed       = 'fixed';
    case Fibonacci   = 'fibonacci';

    /**
     * @throws RandomException
     */
    public function delaySeconds(int $attempt): int
    {
        return match($this) {
            self::Exponential => (int) min(300, (2 ** $attempt) + random_int(0, 1000) / 1000),
            self::Linear      => min(300, 5 * ($attempt + 1)),
            self::Fixed       => 10,
            self::Fibonacci   => (int) min(300, $this->fibonacci($attempt)),
        };
    }

    private function fibonacci(int $n): int
    {
        if ($n <= 1) {
            return 1;
        }
        $a = 1;
        $b = 1;

        for ($i = 2; $i <= $n; ++$i) {
            [$a, $b] = [$b, $a + $b];
        }

        return (int) min(PHP_INT_MAX, $b);
    }

    public function label(): string
    {
        return match($this) {
            self::Exponential => 'Exponential Backoff',
            self::Linear      => 'Linear',
            self::Fixed       => 'Fixed Delay',
            self::Fibonacci   => 'Fibonacci',
        };
    }
}
