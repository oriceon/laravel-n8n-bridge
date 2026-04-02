<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Notifications;

use Illuminate\Support\Facades\Notification;
use Oriceon\N8nBridge\Enums\AlertSeverity;
use Oriceon\N8nBridge\Models\N8nDelivery;
use Oriceon\N8nBridge\Models\N8nQueueJob;
use Oriceon\N8nBridge\Models\N8nWorkflow;

/**
 * Centralized service for dispatching bridge alerts.
 *
 * Called by event listeners — decoupled from business logic.
 */
final class NotificationDispatcher
{
    /**
     * @param N8nQueueJob $job
     * @return void
     */
    public function notifyDeadQueueJob(N8nQueueJob $job): void
    {
        if ( ! $this->isEnabled()) {
            return;
        }

        $notification = new N8nAlertNotification(
            title: 'Queue Job Dead — ' . ($job->workflow->name ?? 'unknown'),
            message: "Queue job [{$job->id}] exhausted {$job->max_attempts} attempts. "
                          . 'Last error: ' . ($job->last_error_message ?? 'unknown'),
            severity: AlertSeverity::Error,
            context: [
                'job_id'         => $job->id,
                'workflow_id'    => $job->workflow_id,
                'priority'       => $job->priority->label(),
                'attempts'       => $job->attempts,
                'failure_reason' => $job->last_failure_reason?->label(),
                'queue'          => $job->queue_name,
                'batch_id'       => $job->batch_id,
            ],
            workflowName: $job->workflow->name ?? null,
            deliveryId: $job->id,
        );

        $this->dispatch($notification);
    }

    /**
     * @param N8nDelivery $delivery
     * @return void
     */
    public function notifyDeliveryDead(N8nDelivery $delivery): void
    {
        if ( ! $this->isEnabled()) {
            return;
        }

        $workflow = $delivery->workflow;

        $notification = N8nAlertNotification::deliveryDead(
            workflowName: $workflow?->name ?? 'unknown',
            deliveryId:   $delivery->id,
            errorMessage: $delivery->error_message ?? 'Unknown error',
            context:      [
                'workflow_id' => $delivery->workflow_id,
                'endpoint_id' => $delivery->endpoint_id,
                'attempts'    => $delivery->attempts,
                'direction'   => $delivery->direction?->value,
                'http_status' => $delivery->http_status,
                'error_class' => $delivery->error_class,
            ],
        );

        $this->dispatch($notification);
    }

    /**
     * @param N8nWorkflow $workflow
     * @param int $failures
     * @return void
     */
    public function notifyCircuitBreakerOpened(N8nWorkflow $workflow, int $failures): void
    {
        if ( ! $this->isEnabled()) {
            return;
        }

        $notification = N8nAlertNotification::circuitBreakerOpened(
            workflowName: $workflow->name,
            failureCount: $failures,
        );

        $this->dispatch($notification);
    }

    /**
     * @param N8nWorkflow $workflow
     * @param float $errorRate
     * @param string $period
     * @return void
     */
    public function notifyHighErrorRate(
        N8nWorkflow $workflow,
        float $errorRate,
        string $period = '1 hour',
    ): void {
        if ( ! $this->isEnabled()) {
            return;
        }

        $threshold = (float) config('n8n-bridge.notifications.error_rate_threshold', 20.0);

        if ($errorRate < $threshold) {
            return;
        }

        $notification = N8nAlertNotification::highErrorRate(
            workflowName: $workflow->name,
            errorRate:    $errorRate,
            period:       $period,
        );

        $this->dispatch($notification);
    }

    /**
     * @param string $title
     * @param string $message
     * @param AlertSeverity $severity
     * @param array $context
     * @param string|null $workflowName
     * @param string|null $deliveryId
     * @return void
     */
    public function notifyCustom(
        string $title,
        string $message,
        AlertSeverity $severity = AlertSeverity::Info,
        array $context = [],
        ?string $workflowName = null,
        ?string $deliveryId = null,
    ): void {
        if ( ! $this->isEnabled()) {
            return;
        }

        $notification = new N8nAlertNotification(
            title:        $title,
            message:      $message,
            severity:     $severity,
            context:      $context,
            workflowName: $workflowName,
            deliveryId:   $deliveryId,
        );

        $this->dispatch($notification);
    }

    /**
     * @param N8nAlertNotification $notification
     * @return void
     */
    private function dispatch(N8nAlertNotification $notification): void
    {
        Notification::send(
            [new N8nAlertNotifiable()],
            $notification
        );
    }

    private function isEnabled(): bool
    {
        return (bool) config('n8n-bridge.notifications.enabled', true);
    }
}
