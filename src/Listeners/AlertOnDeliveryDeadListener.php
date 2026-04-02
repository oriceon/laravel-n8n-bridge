<?php

declare(strict_types=1);

namespace Oriceon\N8nBridge\Listeners;

use Oriceon\N8nBridge\Events\N8nDeliveryDeadEvent;
use Oriceon\N8nBridge\Notifications\NotificationDispatcher;

final readonly class AlertOnDeliveryDeadListener
{
    /**
     * @param NotificationDispatcher $dispatcher
     */
    public function __construct(private NotificationDispatcher $dispatcher)
    {
    }

    /**
     * @param N8nDeliveryDeadEvent $event
     * @return void
     */
    public function handle(N8nDeliveryDeadEvent $event): void
    {
        $this->dispatcher->notifyDeliveryDead($event->delivery);
    }
}
