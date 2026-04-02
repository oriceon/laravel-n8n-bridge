<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Architecture Tests — Pest
|--------------------------------------------------------------------------
| Enforces structural rules using Pest arch presets and expectations.
 */

arch()->preset()->php();
arch()->preset()->security();

arch('all PHP files use strict types')
    ->expect('Oriceon\N8nBridge')
    ->toUseStrictTypes();

arch('enums are backed string enums')
    ->expect('Oriceon\N8nBridge\Enums')
    ->toBeEnums();

arch('DTOs are readonly classes')
    ->expect('Oriceon\N8nBridge\DTOs')
    ->toBeReadonly()
    ->toBeClasses()
    ->ignoring('Oriceon\N8nBridge\DTOs\N8nToolRequest'); // wraps mutable Request object

arch('events are final classes')
    ->expect('Oriceon\N8nBridge\Events')
    ->toBeFinal()
    ->toBeClasses();

arch('pipeline pipes are final')
    ->expect('Oriceon\N8nBridge\Inbound\Pipeline')
    ->toBeFinal()
    ->toBeClasses();

arch('listeners are final')
    ->expect('Oriceon\N8nBridge\Listeners')
    ->toBeFinal()
    ->toBeClasses();

arch('models extend Eloquent Model')
    ->expect('Oriceon\N8nBridge\Models')
    ->toExtend('Illuminate\Database\Eloquent\Model')
    ->ignoring('Oriceon\N8nBridge\Models\Concerns');

arch('commands are final and extend Command')
    ->expect('Oriceon\N8nBridge\Commands')
    ->toBeFinal()
    ->toExtend('Illuminate\Console\Command');

arch('service provider is final')
    ->expect('Oriceon\N8nBridge\N8nBridgeServiceProvider')
    ->toBeFinal();

arch('models do not depend on inbound or command layers')
    ->expect('Oriceon\N8nBridge\Models')
    ->not->toUse('Oriceon\N8nBridge\Inbound')
    ->not->toUse('Oriceon\N8nBridge\Outbound')
    ->not->toUse('Oriceon\N8nBridge\Commands');

arch('tool handlers are abstract')
    ->expect('Oriceon\N8nBridge\Tools\N8nToolHandler')
    ->toBeAbstract();

arch('auth service is final')
    ->expect('Oriceon\N8nBridge\Auth\CredentialAuthService')
    ->toBeFinal();
