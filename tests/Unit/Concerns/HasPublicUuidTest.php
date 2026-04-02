<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Concerns\HasPublicUuid;
use Oriceon\N8nBridge\Models\N8nWorkflow;

covers(HasPublicUuid::class);

it('auto-generates a UUID on creation', function() {
    $workflow = N8nWorkflow::factory()->create();

    expect($workflow->uuid)->toBeString()->not->toBeEmpty()
        ->and(strlen($workflow->uuid))->toBeGreaterThanOrEqual(36);
});

it('does not overwrite a provided UUID', function() {
    $uuid = 'aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee';

    $workflow = N8nWorkflow::factory()->create(['uuid' => $uuid]);

    expect($workflow->uuid)->toBe($uuid);
});

it('getRouteKeyName() returns uuid', function() {
    $workflow = N8nWorkflow::factory()->create();
    expect($workflow->getRouteKeyName())->toBe('uuid');
});

it('findByUuid() resolves the correct record', function() {
    $workflow = N8nWorkflow::factory()->create();

    $found = N8nWorkflow::findByUuid($workflow->uuid);

    expect($found)->not->toBeNull()
        ->and($found->id)->toBe($workflow->id);
});

it('findByUuid() returns null for an unknown uuid', function() {
    expect(N8nWorkflow::findByUuid('00000000-0000-0000-0000-000000000000'))->toBeNull();
});

it('each model gets a unique UUID', function() {
    $a = N8nWorkflow::factory()->create();
    $b = N8nWorkflow::factory()->create();

    expect($a->uuid)->not->toBe($b->uuid);
});
