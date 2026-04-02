<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Oriceon\N8nBridge\Models\N8nCredential;

final class N8nCredentialFactory extends Factory
{
    protected $model = N8nCredential::class;

    public function definition(): array
    {
        return [
            'uuid'         => (string) Str::uuid(),
            'name'         => fake()->words(2, true),
            'description'  => fake()->optional()->sentence(),
            'n8n_instance' => 'default',
            'allowed_ips'  => null,
            'is_active'    => true,
        ];
    }

    public function withIpWhitelist(array $ips): self
    {
        return $this->state(['allowed_ips' => $ips]);
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }
}
