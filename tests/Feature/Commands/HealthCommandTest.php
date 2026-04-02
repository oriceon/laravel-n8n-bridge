<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Oriceon\N8nBridge\Commands\HealthCommand;

covers(HealthCommand::class);

describe('n8n:health', function() {
    it('reports reachable when healthz returns 200', function() {
        Http::fake(['*/healthz*' => Http::response(['status' => 'ok'], 200)]);

        $this->artisan('n8n:health')
            ->expectsOutputToContain('is reachable')
            ->assertSuccessful();
    });

    it('reports unreachable and exits with failure on connection error', function() {
        Http::fake(['*/healthz*' => Http::response(null, 503)]);

        $this->artisan('n8n:health')
            ->expectsOutputToContain('is unreachable')
            ->assertFailed();
    });

    it('accepts --instance option', function() {
        Http::fake(['*/healthz*' => Http::response(['status' => 'ok'], 200)]);

        $this->artisan('n8n:health', ['--instance' => 'default'])
            ->expectsOutputToContain('[default]')
            ->assertSuccessful();
    });
});
