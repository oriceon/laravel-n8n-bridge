<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\DeliveryStatus;
use Oriceon\N8nBridge\Enums\RetryStrategy;

/*
|--------------------------------------------------------------------------
| Shared Datasets
|--------------------------------------------------------------------------
 */

dataset('all delivery statuses', static fn(): array => DeliveryStatus::cases());

dataset('terminal statuses', static fn(): array => DeliveryStatus::terminal());

dataset('retryable statuses', static fn(): array => [
    DeliveryStatus::Failed,
    DeliveryStatus::Retrying,
]);

dataset('all retry strategies', static fn(): array => RetryStrategy::cases());

dataset('valid api key formats', [
    'full format' => ['n8br_wh_abcdefghijklmnopqrstuvwxyz123456'],
    'minimal ok'  => ['n8br_wh_12345678901234567890123456789012'],
]);

dataset('invalid api key formats', [
    'too short'    => ['n8br_wh_short'],
    'wrong prefix' => ['wrong_prefix_123456789012345678901234'],
    'empty'        => [''],
]);

dataset('webhook payloads', [
    'invoice paid' => [
        'payload'   => ['invoice_id' => 42, 'amount' => 1500.00, 'currency' => 'RON'],
        'execution' => 'exec-invoice-001',
    ],
    'order shipped' => [
        'payload'   => ['order_id' => 99, 'tracking' => 'TRK001'],
        'execution' => 'exec-order-001',
    ],
    'empty payload' => [
        'payload'   => [],
        'execution' => 'exec-empty-001',
    ],
]);
