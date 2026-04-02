<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Enums;

enum NotificationChannel: string
{
    case Mail    = 'mail';
    case Slack   = 'slack';
    case Discord = 'discord';
    case Teams   = 'teams';
    case Webhook = 'webhook'; // generic HTTP webhook

    public function label(): string
    {
        return match($this) {
            self::Mail    => 'Email',
            self::Slack   => 'Slack',
            self::Discord => 'Discord',
            self::Teams   => 'Microsoft Teams',
            self::Webhook => 'Generic Webhook',
        };
    }

    public function configKey(): string
    {
        return match($this) {
            self::Mail    => 'n8n-bridge.notifications.mail_to',
            self::Slack   => 'n8n-bridge.notifications.slack_webhook',
            self::Discord => 'n8n-bridge.notifications.discord_webhook',
            self::Teams   => 'n8n-bridge.notifications.teams_webhook',
            self::Webhook => 'n8n-bridge.notifications.generic_webhook',
        };
    }
}
