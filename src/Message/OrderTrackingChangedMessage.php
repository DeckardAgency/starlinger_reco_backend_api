<?php

namespace App\Message;

/**
 * Dispatched when a TrackingEvent is added that changes the latest status
 * (i.e. not a duplicate). Handlers may send customer notifications.
 */
final class OrderTrackingChangedMessage
{
    public function __construct(
        public readonly int $orderId,
        public readonly int $eventId,
    ) {
    }
}
