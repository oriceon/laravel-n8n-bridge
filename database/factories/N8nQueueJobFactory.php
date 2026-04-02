<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Oriceon\N8nBridge\Enums\QueueJobPriority;
use Oriceon\N8nBridge\Enums\QueueJobStatus;
use Oriceon\N8nBridge\Models\N8nQueueJob;
use Oriceon\N8nBridge\Models\N8nWorkflow;

final class N8nQueueJobFactory extends Factory
{
    protected $model = N8nQueueJob::class;

    public function definition(): array
    {
        return [
            'uuid'            => (string) Str::uuid(),
            'workflow_id'     => N8nWorkflow::factory(),
            'priority'        => QueueJobPriority::Normal,
            'status'          => QueueJobStatus::Pending,
            'payload'         => ['invoice_id' => $this->faker->numberBetween(1, 10000)],
            'context'         => null,
            'n8n_instance'    => 'default',
            'attempts'        => 0,
            'max_attempts'    => 3,
            'timeout_seconds' => 120,
            'queue_name'      => 'default',
            'available_at'    => null,
        ];
    }

    public function pending(): self
    {
        return $this->state(['status' => QueueJobStatus::Pending]);
    }

    public function failed(): self
    {
        return $this->state([
            'status'              => QueueJobStatus::Failed,
            'attempts'            => 1,
            'last_failure_reason' => 'http_5xx',
            'last_error_message'  => 'Service Unavailable',
        ]);
    }

    public function dead(): self
    {
        return $this->state([
            'status'              => QueueJobStatus::Dead,
            'attempts'            => 3,
            'max_attempts'        => 3,
            'last_failure_reason' => 'http_5xx',
            'last_error_message'  => 'Exhausted all retry attempts',
        ]);
    }

    public function done(): self
    {
        return $this->state([
            'status'      => QueueJobStatus::Done,
            'attempts'    => 1,
            'started_at'  => now()->subSeconds(2),
            'finished_at' => now(),
            'duration_ms' => 1842,
        ]);
    }

    public function critical(): self
    {
        return $this->state([
            'priority'     => QueueJobPriority::Critical,
            'max_attempts' => 10,
        ]);
    }

    public function bulk(): self
    {
        return $this->state([
            'priority'     => QueueJobPriority::Bulk,
            'max_attempts' => 2,
            'queue_name'   => 'bulk',
        ]);
    }

    public function delayed(int $seconds = 60): self
    {
        return $this->state(['available_at' => now()->addSeconds($seconds)]);
    }
}
