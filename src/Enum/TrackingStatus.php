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
     * Map a DHL Express (MyDHL API tracking) checkpoint to our enum.
     *
     * DHL identifies checkpoints by a two-letter typeCode; the well-known ones are
     * mapped explicitly, anything else falls back to keywords in the description
     * so an unlisted code still lands on a sensible status instead of a crash.
     */
    public static function fromDhlExpressEvent(string $typeCode, ?string $description = null): self
    {
        $mapped = match (strtoupper(trim($typeCode))) {
            'SM', 'SI' => self::CREATED,                 // shipment information received
            'PU', 'SA' => self::PICKED_UP,               // picked up / shipment acceptance
            'WC', 'OD' => self::OUT_FOR_DELIVERY,        // with delivery courier
            'OK', 'DD' => self::DELIVERED,               // delivered (DD: delivered damaged)
            'RT', 'RR' => self::RETURNED,                // returned to shipper
            'ND', 'NH', 'MD', 'RD', 'BA', 'OH', 'HO',
            'CD', 'CA', 'SS', 'TD', 'UD', 'HP', 'DM' => self::EXCEPTION, // not delivered, hold, delays, clearance delay
            'PL', 'DF', 'AF', 'AR', 'TR', 'CR', 'CC', 'IC', 'FD' => self::IN_TRANSIT,
            default => null,
        };
        if ($mapped) {
            return $mapped;
        }

        $text = strtolower((string) $description);

        return match (true) {
            str_contains($text, 'delivered') && !str_contains($text, 'not delivered') => self::DELIVERED,
            str_contains($text, 'with delivery courier'), str_contains($text, 'out for delivery') => self::OUT_FOR_DELIVERY,
            str_contains($text, 'picked up') => self::PICKED_UP,
            str_contains($text, 'returned') => self::RETURNED,
            str_contains($text, 'information received'), str_contains($text, 'label created') => self::CREATED,
            str_contains($text, 'not delivered'), str_contains($text, 'on hold'), str_contains($text, 'delay'),
            str_contains($text, 'refused'), str_contains($text, 'bad address'), str_contains($text, 'missed') => self::EXCEPTION,
            default => self::IN_TRANSIT,
        };
    }
}
