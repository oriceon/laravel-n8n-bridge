<?php

declare(strict_types=1);

use Oriceon\N8nBridge\Enums\NotificationChannel;

covers(NotificationChannel::class);

describe('NotificationChannel', function() {
    it('has a label for every case', function(NotificationChannel $channel) {
        expect($channel->label())->toBeString()->not->toBeEmpty();
    })->with(NotificationChannel::cases());

    it('has a configKey for every case', function(NotificationChannel $channel) {
        expect($channel->configKey())->toStartWith('n8n-bridge.notifications.');
    })->with(NotificationChannel::cases());

    it('configKeys are unique across all channels', function() {
        $keys = array_map(static fn($c) => $c->configKey(), NotificationChannel::cases());
        expect($keys)->toBe(array_unique($keys));
    });

    it('can round-trip through string value', function(NotificationChannel $channel) {
        expect(NotificationChannel::from($channel->value))->toBe($channel);
    })->with(NotificationChannel::cases());

    it('tryFrom returns null for unknown value', function() {
        expect(NotificationChannel::tryFrom('telegram'))->toBeNull();
    });
});
