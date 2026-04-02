<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Notifications;

use Illuminate\Notifications\Notifiable;

/**
 * Anonymous notifiable for routing package alerts.
 *
 * Reads routing config at runtime, so no app code changes are needed.
 */
final class N8nAlertNotifiable
{
    use Notifiable;

    /**
     * Route mail notifications.
     */
    public function routeNotificationForMail(): string|array
    {
        return config('n8n-bridge.notifications.mail_to', '');
    }

    /**
     * Route Slack notifications (webhook URL).
     */
    public function routeNotificationForSlack(): string
    {
        return config('n8n-bridge.notifications.slack_webhook', '');
    }

    /**
     * Route Discord notifications (webhook URL).
     */
    public function routeNotificationForDiscord(): string
    {
        return config('n8n-bridge.notifications.discord_webhook', '');
    }

    /**
     * Route Teams notifications (webhook URL).
     */
    public function routeNotificationForTeams(): string
    {
        return config('n8n-bridge.notifications.teams_webhook', '');
    }

    /**
     * Route generic webhook notifications (URL).
     */
    public function routeNotificationForWebhook(): string
    {
        return config('n8n-bridge.notifications.generic_webhook', '');
    }

    /**
     * Required by Laravel's NotificationFake for test assertions.
     */
    public function getKey(): string
    {
        return 'n8n-alert-notifiable';
    }
}
