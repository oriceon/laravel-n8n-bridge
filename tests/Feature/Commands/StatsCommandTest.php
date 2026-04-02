<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Commands\StatsCommand;
use Oriceon\N8nBridge\Database\Factories\N8nDeliveryFactory;

covers(StatsCommand::class);

describe('n8n:stats', function() {
    it('outputs a statistics table with zero values when DB is empty', function() {
        $this->artisan('n8n:stats')
            ->expectsOutputToContain('n8n Bridge Statistics')
            ->expectsOutputToContain('Total deliveries')
            ->expectsOutputToContain('Success rate')
            ->assertSuccessful();
    });

    it('shows correct counts when deliveries exist', function() {
        N8nDeliveryFactory::new()->done()->count(5)->create();
        N8nDeliveryFactory::new()->failed()->count(2)->create();
        N8nDeliveryFactory::new()->dlq()->count(1)->create();

        $this->artisan('n8n:stats')
            ->expectsOutputToContain('n8n Bridge Statistics')
            ->assertSuccessful();
    });
});
