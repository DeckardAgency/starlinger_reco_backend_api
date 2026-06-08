<?php

namespace App\Service\Carrier\Dto;

use App\Enum\TrackingStatus;

/**
 * Transport-only DTO for tracking events fetched from carrier APIs.
 * Mapped to TrackingEvent entities by TrackingService.
 */
final class TrackingEventDto
{
    public function __construct(
        public readonly TrackingStatus $status,
        public readonly \DateTimeInterface $occurredAt,
        public readonly ?string $description = null,
        public readonly ?string $location = null,
    ) {
    }
}
