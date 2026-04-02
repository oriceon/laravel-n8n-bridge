<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;
use Oriceon\N8nBridge\Bridge\Tests\TestCase;
use Oriceon\N8nBridge\Models\N8nCredential;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Oriceon\N8nBridge\Models\N8nWorkflow;

pest()
    ->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature', 'Unit', 'Architecture');

/*
|--------------------------------------------------------------------------
| Global Expectations
|--------------------------------------------------------------------------
 */
expect()->extend('toBeEnum', function(string $enumClass) {
    expect($this->value)->toBeInstanceOf($enumClass);

    return $this;
});

expect()->extend('toHaveStatus', function(string $status) {
    expect($this->value->status->value ?? $this->value->status)->toBe($status);

    return $this;
});

expect()->extend('toBeActiveModel', function() {
    expect($this->value->is_active)->toBeTrue();

    return $this;
});

/*
|--------------------------------------------------------------------------
| Global Functions
|--------------------------------------------------------------------------
 */
function makeWorkflow(array $attrs = []): N8nWorkflow
{
    return N8nWorkflow::factory()->create($attrs);
}

function makeEndpoint(array $attrs = []): N8nEndpoint
{
    return N8nEndpoint::factory()->create($attrs);
}

function makeDelivery(array $attrs = []): N8nDelivery
{
    return N8nDelivery::factory()->create($attrs);
}

function makeCredentialWithKey(): array
{
    $credential = N8nCredential::create([
        'name'      => 'Test Credential',
        'is_active' => true,
    ]);

    [$key] = $credential->generateKey();

    return [$credential, $key];
}
