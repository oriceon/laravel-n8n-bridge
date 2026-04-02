<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Listeners;

use Oriceon\N8nBridge\Events\N8nCircuitBreakerOpenedEvent;
use Oriceon\N8nBridge\Notifications\NotificationDispatcher;

final readonly class AlertOnCircuitBreakerOpenedListener
{
    /**
     * @param NotificationDispatcher $dispatcher
     */
    public function __construct(private NotificationDispatcher $dispatcher)
    {
    }

    /**
     * @param N8nCircuitBreakerOpenedEvent $event
     * @return void
     */
    public function handle(N8nCircuitBreakerOpenedEvent $event): void
    {
        $this->dispatcher->notifyCircuitBreakerOpened($event->workflow, $event->failureCount);
    }
}
