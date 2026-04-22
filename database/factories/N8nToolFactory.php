<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;
use Oriceon\N8nBridge\Models\N8nCredential;
use Oriceon\N8nBridge\Models\N8nTool;

final class N8nToolFactory extends Factory
{
    protected $model = N8nTool::class;

    public function definition(): array
    {
        return [
            'uuid' => (string) Str::uuid(),
            'name' => fake()->unique()->slug(2),
            'label' => fake()->words(2, true),
            'description' => fake()->optional()->sentence(),
            'category' => fake()->optional()->word(),
            'handler_class' => 'App\\N8n\\Tools\\DefaultTool',
            'allowed_methods' => null,
            'allowed_ips' => null,
            'rate_limit' => 120,
            'request_schema' => ['type' => 'object'],
            'response_schema' => ['type' => 'object'],
            'examples' => null,
            'is_active' => true,
        ];
    }

    /**
     * Attach the given credential to the tool via the pivot table.
     * Can be called multiple times to attach multiple credentials.
     */
    public function forCredential(N8nCredential $credential): self
    {
        return $this->afterCreating(function (N8nTool $tool) use ($credential): void {
            $tool->credentials()->syncWithoutDetaching([$credential->id]);
        });
    }

    public function withGet(): self
    {
        return $this->state(['allowed_methods' => ['GET', 'POST']]);
    }

    public function readOnly(): self
    {
        return $this->state(['allowed_methods' => ['GET']]);
    }

    public function inactive(): self
    {
        return $this->state(['is_active' => false]);
    }

    public function inCategory(string $category): self
    {
        return $this->state(['category' => $category]);
    }

    public function open(): self
    {
        // Tool with no credentials attached — any authenticated caller can use it
        return $this;
    }
}
