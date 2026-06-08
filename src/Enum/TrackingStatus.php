<?php

namespace App\Enum;

enum TrackingStatus: string
{
    case CREATED = 'created';
    case PICKED_UP = 'picked_up';
    case IN_TRANSIT = 'in_transit';
    case OUT_FOR_DELIVERY = 'out_for_delivery';
    case DELIVERED = 'delivered';
    case EXCEPTION = 'exception';
    case RETURNED = 'returned';

    public function label(): string
    {
        return match ($this) {
            self::CREATED => 'Label created',
            self::PICKED_UP => 'Picked up',
            self::IN_TRANSIT => 'In transit',
            self::OUT_FOR_DELIVERY => 'Out for delivery',
            self::DELIVERED => 'Delivered',
            self::EXCEPTION => 'Exception',
            self::RETURNED => 'Returned',
        };
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::DELIVERED, self::RETURNED], true);
    }

    /**
     * Map a DHL Track-and-Trace status code to our enum.
     * DHL codes: pre-transit, transit, delivered, failure, unknown
     */
    public static function fromDhlStatus(string $dhlStatus): self
    {
        return match (strtolower(trim($dhlStatus))) {
            'pre-transit', 'created' => self::CREATED,
            'transit', 'in-transit', 'in_transit' => self::IN_TRANSIT,
            'delivered' => self::DELIVERED,
            'failure', 'exception' => self::EXCEPTION,
            default => self::IN_TRANSIT,
        };
    }
}
