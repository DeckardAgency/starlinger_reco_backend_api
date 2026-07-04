<?php

namespace App\Message;

/**
 * Marker message dispatched by the tracking schedule (and available for ad-hoc
 * dispatch) to refresh carrier tracking for all pending orders.
 */
class RefreshPendingTrackingMessage
{
}
