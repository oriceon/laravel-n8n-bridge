<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Oriceon\N8nBridge\Enums\DeliveryDirection;
use Oriceon\N8nBridge\Enums\DeliveryStatus;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nWorkflow;

final class N8nDeliveryFactory extends Factory
{
    protected $model = N8nDelivery::class;

    public function definition(): array
    {
        return [
            'uuid'             => (string) Str::uuid(),
            'workflow_id'      => N8nWorkflow::factory(),
            'endpoint_id'      => null,
            'direction'        => DeliveryDirection::Inbound->value,
            'status'           => DeliveryStatus::Received->value,
            'idempotency_key'  => fake()->uuid(),
            'payload'          => ['event' => 'test', 'id' => fake()->randomNumber()],
            'response'         => null,
            'http_status'      => null,
            'duration_ms'      => null,
            'attempts'         => 0,
            'error_message'    => null,
            'error_class'      => null,
            'n8n_execution_id' => null,
            'processed_at'     => null,
        ];
    }

    public function done(): self
    {
        return $this->state([
            'status'       => DeliveryStatus::Done->value,
            'duration_ms'  => fake()->numberBetween(50, 2000),
            'http_status'  => 200,
            'processed_at' => now(),
        ]);
    }

    public function failed(): self
    {
        return $this->state([
            'status'        => DeliveryStatus::Failed->value,
            'attempts'      => 1,
            'error_message' => 'Connection refused',
            'error_class'   => \RuntimeException::class,
        ]);
    }

    public function dlq(): self
    {
        return $this->state([
            'status'        => DeliveryStatus::Dlq->value,
            'attempts'      => 3,
            'error_message' => 'Exhausted all retries',
            'error_class'   => \RuntimeException::class,
        ]);
    }

    public function outbound(): self
    {
        return $this->state(['direction' => DeliveryDirection::Outbound->value]);
    }
}
