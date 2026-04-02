<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Notification;
use Oriceon\N8nBridge\Enums\DeliveryStatus;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nEndpoint;
use Oriceon\N8nBridge\Models\N8nWorkflow;
use Oriceon\N8nBridge\Notifications\N8nAlertNotifiable;
use Oriceon\N8nBridge\Notifications\N8nAlertNotification;
use Oriceon\N8nBridge\Notifications\NotificationDispatcher;

covers(NotificationDispatcher::class, N8nAlertNotification::class);

describe('NotificationDispatcher', function() {
    beforeEach(function() {
        config(['n8n-bridge.notifications.enabled' => true]);
        config(['n8n-bridge.notifications.channels' => ['mail']]);
        config(['n8n-bridge.notifications.mail_to' => 'ops@example.com']);

        $this->dispatcher = app(NotificationDispatcher::class);
        $this->workflow   = N8nWorkflow::factory()->create(['name' => 'invoice-created']);
        $this->endpoint   = N8nEndpoint::factory()->create();
    });

    it('sends notification on delivery dead', function() {
        Notification::fake();

        $delivery = N8nDelivery::factory()->create([
            'workflow_id'   => $this->workflow->id,
            'endpoint_id'   => $this->endpoint->id,
            'status'        => DeliveryStatus::Dlq,
            'error_message' => 'Connection refused',
            'attempts'      => 3,
        ]);

        $this->dispatcher->notifyDeliveryDead($delivery);

        Notification::assertSentTo(
            [new N8nAlertNotifiable()],
            N8nAlertNotification::class,
        );
    });

    it('sends circuit breaker alert', function() {
        Notification::fake();

        $this->dispatcher->notifyCircuitBreakerOpened($this->workflow, 5);

        Notification::assertSentTo(
            [new N8nAlertNotifiable()],
            N8nAlertNotification::class,
        );
    });

    it('does not send when notifications are disabled', function() {
        config(['n8n-bridge.notifications.enabled' => false]);
        Notification::fake();

        $delivery = N8nDelivery::factory()->create([
            'workflow_id' => $this->workflow->id,
            'status'      => DeliveryStatus::Dlq,
        ]);

        $this->dispatcher->notifyDeliveryDead($delivery);

        Notification::assertNothingSent();
    });

    it('does not send high error rate below threshold', function() {
        config(['n8n-bridge.notifications.error_rate_threshold' => 20.0]);
        Notification::fake();

        $this->dispatcher->notifyHighErrorRate($this->workflow, 15.0, '1 hour');

        Notification::assertNothingSent();
    });

    it('sends high error rate above threshold', function() {
        config(['n8n-bridge.notifications.error_rate_threshold' => 20.0]);
        Notification::fake();

        $this->dispatcher->notifyHighErrorRate($this->workflow, 25.0, '1 hour');

        Notification::assertSentTo(
            [new N8nAlertNotifiable()],
            N8nAlertNotification::class,
        );
    });
});

describe('N8nAlertNotification', function() {
    it('creates correct delivery-dead notification', function() {
        $notif = N8nAlertNotification::deliveryDead(
            workflowName: 'invoice-flow',
            deliveryId: 'delivery-abc-123',
            errorMessage: 'Timeout after 30s',
        );

        expect($notif)->toBeInstanceOf(N8nAlertNotification::class);
    });

    it('creates correct circuit-breaker notification', function() {
        $notif = N8nAlertNotification::circuitBreakerOpened(
            workflowName: 'payment-flow',
            failureCount: 5,
        );

        expect($notif)->toBeInstanceOf(N8nAlertNotification::class);
    });

    it('creates correct high-error-rate notification', function() {
        $notif = N8nAlertNotification::highErrorRate(
            workflowName: 'sync-flow',
            errorRate: 35.5,
            period: '1 hour',
        );

        expect($notif)->toBeInstanceOf(N8nAlertNotification::class);
    });

    it('builds Discord payload correctly', function() {
        config(['n8n-bridge.notifications.channels' => ['discord']]);
        config(['n8n-bridge.notifications.discord_webhook' => 'https://discord.com/webhook/test']);

        $notif = N8nAlertNotification::deliveryDead('test-flow', 'del-001', 'Error msg');

        $discord = $notif->toDiscord(new N8nAlertNotifiable());

        expect($discord)->toHaveKey('embeds')
            ->and($discord['embeds'][0])->toHaveKey('title')
            ->and($discord['embeds'][0])->toHaveKey('color')
            ->and($discord['embeds'][0]['footer']['text'])->toBe('laravel-n8n-bridge');
    });

    it('builds Teams payload correctly', function() {
        $notif = N8nAlertNotification::circuitBreakerOpened('test-flow', 3);

        $teams = $notif->toTeams(new N8nAlertNotifiable());

        expect($teams)->toHaveKey('@type')
            ->and($teams['@type'])->toBe('MessageCard')
            ->and($teams)->toHaveKey('sections')
            ->and($teams['sections'][0])->toHaveKey('facts');
    });

    it('builds generic webhook payload correctly', function() {
        $notif = N8nAlertNotification::deliveryDead('test-flow', 'del-002', 'Error');

        $webhook = $notif->toWebhook(new N8nAlertNotifiable());

        expect($webhook)->toHaveKey('source')
            ->and($webhook['source'])->toBe('laravel-n8n-bridge')
            ->and($webhook)->toHaveKey('severity')
            ->and($webhook)->toHaveKey('timestamp');
    });
});
