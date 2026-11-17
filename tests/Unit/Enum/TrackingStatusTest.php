<?php

declare(strict_types=1);

namespace App\Tests\Unit\Enum;

use App\Enum\TrackingStatus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TrackingStatusTest extends TestCase
{
    #[DataProvider('expressEvents')]
    public function testDhlExpressCheckpointMapping(string $typeCode, ?string $description, TrackingStatus $expected): void
    {
        self::assertSame($expected, TrackingStatus::fromDhlExpressEvent($typeCode, $description));
    }

    public static function expressEvents(): iterable
    {
        yield 'picked up' => ['PU', 'Shipment picked up', TrackingStatus::PICKED_UP];
        yield 'lowercase code' => ['pu', null, TrackingStatus::PICKED_UP];
        yield 'processed at facility' => ['PL', 'Processed at DHL facility', TrackingStatus::IN_TRANSIT];
        yield 'departed' => ['DF', 'Departed Facility in VIENNA', TrackingStatus::IN_TRANSIT];
        yield 'customs released' => ['CR', 'Clearance processing complete', TrackingStatus::IN_TRANSIT];
        yield 'with courier' => ['WC', 'Shipment is out with courier for delivery', TrackingStatus::OUT_FOR_DELIVERY];
        yield 'delivered' => ['OK', 'Delivered - Signed for by: J.DOE', TrackingStatus::DELIVERED];
        yield 'returned' => ['RT', 'Shipment returned to shipper', TrackingStatus::RETURNED];
        yield 'not home' => ['NH', 'Recipient not home', TrackingStatus::EXCEPTION];
        yield 'clearance delay' => ['CD', 'Clearance delay', TrackingStatus::EXCEPTION];
        yield 'information received' => ['SM', 'Shipment information received', TrackingStatus::CREATED];
        // Unknown codes fall back to the description
        yield 'unknown code, delivered text' => ['ZZ', 'Delivered to reception', TrackingStatus::DELIVERED];
        yield 'unknown code, not delivered text' => ['ZZ', 'Not delivered, recipient refused', TrackingStatus::EXCEPTION];
        yield 'unknown code, courier text' => ['ZZ', 'Out for delivery', TrackingStatus::OUT_FOR_DELIVERY];
        yield 'unknown code, no text' => ['ZZ', null, TrackingStatus::IN_TRANSIT];
    }
}
