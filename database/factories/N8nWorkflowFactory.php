<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Oriceon\N8nBridge\Auth\WebhookAuthService;
use Oriceon\N8nBridge\Enums\HttpMethod;
use Oriceon\N8nBridge\Enums\WebhookAuthType;
use Oriceon\N8nBridge\Models\N8nWorkflow;
use Random\RandomException;

class N8nWorkflowFactory extends Factory
{
    protected $model = N8nWorkflow::class;

    public function definition(): array
    {
        return [
            'uuid'         => (string) Str::uuid(),
            'n8n_id'       => fake()->lexify(str_repeat('?', 16)),
            'n8n_instance' => 'default',
            'name'         => fake()->words(3, true),
            'description'  => fake()->sentence(),
            'webhook_path' => fake()->slug(),
            'auth_type'    => WebhookAuthType::None->value,
            'auth_key'     => null,
            'http_method'  => HttpMethod::Post->value,
            'is_active'    => true,
            'tags'         => [],
        ];
    }

    public function inactive(): static
    {
        return $this->state(['is_active' => false]);
    }

    public function synced(): static
    {
        return $this->state(['n8n_id' => fake()->uuid()]);
    }

    /**
     * Set the outbound authentication type and key.
     *
     * @param WebhookAuthType $type
     * @param string|null $key Plaintext key (will be encrypted by model cast).
     *                          Pass null to auto-generate a 64-char hex key.
     *
     * @throws RandomException
     * @return N8nWorkflowFactory
     */
    public function withAuth(WebhookAuthType $type, ?string $key = null): static
    {
        return $this->state([
            'auth_type' => $type->value,
            'auth_key'  => $key ?? WebhookAuthService::generateKey(),
        ]);
    }
}
