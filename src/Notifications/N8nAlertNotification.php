<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Messages\SlackMessage;
use Illuminate\Notifications\Notification;
use Oriceon\N8nBridge\Enums\AlertSeverity;
use Oriceon\N8nBridge\Enums\NotificationChannel;

/**
 * Universal alert notification for n8n bridge failures.
 *
 * Supports: mail, slack, discord, teams, webhook.
 * Channels are read from config at runtime — no code changes needed.
 */
final class N8nAlertNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param string $title
     * @param string $message
     * @param AlertSeverity $severity
     * @param array $context
     * @param string|null $workflowName
     * @param string|null $deliveryId
     */
    public function __construct(
        private readonly string $title,
        private readonly string $message,
        private readonly AlertSeverity $severity,
        private readonly array $context = [],
        private readonly ?string $workflowName = null,
        private readonly ?string $deliveryId = null,
    ) {
    }

    /**
     * @param mixed $notifiable
     * @return list<string>
     */
    public function via(mixed $notifiable): array
    {
        $configured = config('n8n-bridge.notifications.channels', []);
        $channels   = [];

        foreach ($configured as $channel) {
            $enum = NotificationChannel::tryFrom($channel);

            if ($enum === null) {
                continue;
            }
            $configValue = config($enum->configKey());

            if ( ! empty($configValue)) {
                $channels[] = match ($enum) {
                    NotificationChannel::Mail    => 'mail',
                    NotificationChannel::Slack   => 'slack',
                    NotificationChannel::Discord => 'discord',
                    NotificationChannel::Teams   => 'teams',
                    NotificationChannel::Webhook => 'webhook',
                };
            }
        }

        return $channels;
    }

    // ── Mail ──────────────────────────────────────────────────────────────────

    /**
     * @param mixed $notifiable
     * @throws \JsonException
     * @return MailMessage
     */
    public function toMail(mixed $notifiable): MailMessage
    {
        $mail = new MailMessage()
            ->subject($this->severity->emoji() . ' [n8n Bridge] ' . $this->title)
            ->greeting('n8n Bridge Alert')
            ->line($this->message)
            ->line('**Severity:** ' . $this->severity->value);

        if ($this->workflowName) {
            $mail->line('**Workflow:** ' . $this->workflowName);
        }

        if ($this->deliveryId) {
            $mail->line('**Delivery ID:** ' . $this->deliveryId);
        }

        if ( ! empty($this->context)) {
            $mail->line('**Context:**')
                ->line('```' . json_encode($this->context, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . '```');
        }

        return $mail->line('Sent by laravel-n8n-bridge');
    }

    // ── Slack ─────────────────────────────────────────────────────────────────

    /**
     * @param mixed $notifiable
     * @return SlackMessage
     */
    public function toSlack(mixed $notifiable): SlackMessage
    {
        $slack = new SlackMessage()
            ->from('n8n Bridge', ':robot_face:')
            ->to(config('n8n-bridge.notifications.slack_channel', '#n8n-alerts'))
            ->attachment(function($a) {
                $a->title($this->severity->emoji() . ' ' . $this->title)
                    ->content($this->message)
                    ->color($this->severity->color())
                    ->fields(array_filter([
                        'Severity'    => $this->severity->value,
                        'Workflow'    => $this->workflowName,
                        'Delivery ID' => $this->deliveryId,
                    ]))
                    ->timestamp(now());
            });

        return $slack;
    }

    // ── Discord ───────────────────────────────────────────────────────────────

    /**
     * @param mixed $notifiable
     * @return array<string, mixed>
     */
    public function toDiscord(mixed $notifiable): array
    {
        return [
            'username' => 'n8n Bridge',
            'embeds'   => [[
                'title'       => $this->severity->emoji() . ' ' . $this->title,
                'description' => $this->message,
                'color'       => hexdec(ltrim($this->severity->color(), '#')),
                'fields'      => array_filter([
                    ['name' => 'Severity',    'value' => $this->severity->value,  'inline' => true],
                    ['name' => 'Workflow',    'value' => $this->workflowName ?? '—', 'inline' => true],
                    ['name' => 'Delivery ID', 'value' => $this->deliveryId ?? '—',  'inline' => false],
                ]),
                'timestamp' => now()->toIso8601String(),
                'footer'    => ['text' => 'laravel-n8n-bridge'],
            ]],
        ];
    }

    // ── Microsoft Teams ───────────────────────────────────────────────────────

    /**
     * @param mixed $notifiable
     * @return array<string, mixed>
     */
    public function toTeams(mixed $notifiable): array
    {
        return [
            '@type'      => 'MessageCard',
            '@context'   => 'http://schema.org/extensions',
            'themeColor' => ltrim($this->severity->color(), '#'),
            'summary'    => $this->title,
            'sections'   => [[
                'activityTitle'    => $this->severity->emoji() . ' ' . $this->title,
                'activitySubtitle' => 'laravel-n8n-bridge',
                'activityText'     => $this->message,
                'facts'            => array_values(array_filter([
                    ['name' => 'Severity',    'value' => $this->severity->value],
                    $this->workflowName ? ['name' => 'Workflow', 'value' => $this->workflowName] : null,
                    $this->deliveryId ? ['name' => 'Delivery', 'value' => $this->deliveryId] : null,
                ])),
            ]],
        ];
    }

    // ── Generic webhook ───────────────────────────────────────────────────────

    /**
     * @param mixed $notifiable
     * @return array<string, mixed>
     */
    public function toWebhook(mixed $notifiable): array
    {
        return [
            'source'        => 'laravel-n8n-bridge',
            'title'         => $this->title,
            'message'       => $this->message,
            'severity'      => $this->severity->value,
            'workflow_name' => $this->workflowName,
            'delivery_id'   => $this->deliveryId,
            'context'       => $this->context,
            'timestamp'     => now()->toIso8601String(),
        ];
    }

    // ── Factories ─────────────────────────────────────────────────────────────

    /**
     * @param string $workflowName
     * @param int|string $deliveryId
     * @param string $errorMessage
     * @param array $context
     * @return self
     */
    public static function deliveryDead(
        string $workflowName,
        int|string $deliveryId,
        string $errorMessage,
        array $context = [],
    ): self {
        $deliveryId = (string) $deliveryId;

        return new self(
            title:        'Delivery entered Dead Letter Queue',
            message:      "Delivery [{$deliveryId}] for workflow [{$workflowName}] failed all retries.\n\nError: {$errorMessage}",
            severity:     AlertSeverity::Error,
            context:      $context,
            workflowName: $workflowName,
            deliveryId:   $deliveryId,
        );
    }

    /**
     * @param string $workflowName
     * @param int $failureCount
     * @return self
     */
    public static function circuitBreakerOpened(
        string $workflowName,
        int $failureCount,
    ): self {
        return new self(
            title:        'Circuit Breaker Opened',
            message:      "Workflow [{$workflowName}] has been blocked after {$failureCount} consecutive failures.",
            severity:     AlertSeverity::Critical,
            context:      ['failure_count' => $failureCount],
            workflowName: $workflowName,
        );
    }

    /**
     * @param string $workflowName
     * @param float $errorRate
     * @param string $period
     * @return self
     */
    public static function highErrorRate(
        string $workflowName,
        float $errorRate,
        string $period,
    ): self {
        return new self(
            title:        'High Error Rate Detected',
            message:      "Workflow [{$workflowName}] has a {$errorRate}% error rate in the last {$period}.",
            severity:     AlertSeverity::Warning,
            context:      ['error_rate' => $errorRate, 'period' => $period],
            workflowName: $workflowName,
        );
    }
}
