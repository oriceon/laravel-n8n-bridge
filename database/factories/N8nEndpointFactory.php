<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Oriceon\N8nBridge\Enums\AuthType;
use Oriceon\N8nBridge\Enums\RetryStrategy;
use Oriceon\N8nBridge\Models\N8nCredential;
use Oriceon\N8nBridge\Models\N8nEndpoint;

final class N8nEndpointFactory extends Factory
{
    protected $model = N8nEndpoint::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'slug' => fake()->unique()->slug(),
            'auth_type' => AuthType::ApiKey->value,
            'handler_class' => 'App\\N8n\\DefaultHandler',
            'queue' => 'default',
            'allowed_ips' => null,
            'verify_hmac' => false,
            'hmac_secret' => null,
            'rate_limit' => 60,
            'store_payload' => true,
            'retry_strategy' => RetryStrategy::Exponential->value,
            'max_attempts' => 3,
            'expires_at' => null,
            'is_active' => true,
        ];
    }

    /**
     * Attach the given credential to the endpoint via the pivot table.
     * Can be called multiple times to attach multiple credentials.
     */
    public function forCredential(N8nCredential $credential): self
    {
        return $this->afterCreating(function (N8nEndpoint $endpoint) use ($credential): void {
            $endpoint->credentials()->syncWithoutDetaching([$credential->id]);
        });
    }

    public function withHmac(): self
    {
        return $this->state([
            'verify_hmac' => true,
            'hmac_secret' => fake()->uuid(),
        ]);
    }

    public function withIpWhitelist(array $ips = ['127.0.0.1']): self
    {
        return $this->state(['allowed_ips' => $ips]);
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }

    public function expired(): self
    {
        return $this->state(['expires_at' => now()->subHour()]);
    }
}
