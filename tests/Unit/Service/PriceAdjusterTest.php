<?php

declare(strict_types=1);

namespace App\Tests\Unit\Service;

use App\Service\AdjustedPrice;
use App\Service\PriceAdjuster;
use App\Service\ResolvedDiscount;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The single piece of pricing arithmetic shared by the product serializer, the
 * order snapshot and the client-product endpoints.
 */
final class PriceAdjusterTest extends TestCase
{
    private PriceAdjuster $adjuster;

    protected function setUp(): void
    {
        $this->adjuster = new PriceAdjuster();
    }

    public function testNegativePercentIsADiscount(): void
    {
        $r = $this->adjuster->apply(100.0, $this->percent(-20));

        $this->assertPrice($r, final: 80.0, isDiscount: true, isMarkup: false, display: 20.0, original: 100.0);
    }

    public function testPositivePercentIsAMarkupShownAsAPlainPrice(): void
    {
        $r = $this->adjuster->apply(100.0, $this->percent(20));

        $this->assertPrice($r, final: 120.0, isDiscount: false, isMarkup: true, display: 0.0, original: 120.0);
    }

    public function testFixedPriceBelowBaseIsADiscountWithDerivedPercent(): void
    {
        $r = $this->adjuster->apply(100.0, new ResolvedDiscount(0.0, 55.0, 'campaign'));

        $this->assertPrice($r, final: 55.0, isDiscount: true, isMarkup: false, display: 45.0, original: 100.0);
    }

    public function testFixedPriceAboveBaseChangesNothing(): void
    {
        $r = $this->adjuster->apply(100.0, new ResolvedDiscount(0.0, 150.0, 'campaign'));

        $this->assertPrice($r, final: 100.0, isDiscount: false, isMarkup: false, display: 0.0, original: 100.0);
    }

    public function testFixedPriceEqualToBaseChangesNothing(): void
    {
        $r = $this->adjuster->apply(100.0, new ResolvedDiscount(0.0, 100.0, 'campaign'));

        $this->assertPrice($r, final: 100.0, isDiscount: false, isMarkup: false, display: 0.0, original: 100.0);
    }

    public function testFixedPriceOnZeroBaseDoesNotDivideByZero(): void
    {
        $r = $this->adjuster->apply(0.0, new ResolvedDiscount(0.0, 55.0, 'campaign'));

        $this->assertPrice($r, final: 0.0, isDiscount: false, isMarkup: false, display: 0.0, original: 0.0);
    }

    public function testNoAdjustmentReturnsTheBasePrice(): void
    {
        $this->assertPrice($this->adjuster->apply(99.5, null), final: 99.5, isDiscount: false, isMarkup: false, display: 0.0, original: 99.5);
        $this->assertPrice($this->adjuster->apply(99.5, $this->percent(0)), final: 99.5, isDiscount: false, isMarkup: false, display: 0.0, original: 99.5);
    }

    #[DataProvider('roundingCases')]
    public function testPricesAreRoundedToCents(float $base, float $percent, float $expected): void
    {
        self::assertSame($expected, $this->adjuster->apply($base, $this->percent($percent))->finalPrice);
    }

    /** @return iterable<string, array{float, float, float}> */
    public static function roundingCases(): iterable
    {
        yield '-15% of 99.99 = 84.9915 -> 84.99' => [99.99, -15.0, 84.99];
        yield '+20% of 33.33 = 39.996 -> 40.00' => [33.33, 20.0, 40.0];
        yield '-33.3333% of 10 = 6.66667 -> 6.67' => [10.0, -33.3333, 6.67];
        yield '-12.5% of 7.99 = 6.99125 -> 6.99' => [7.99, -12.5, 6.99];
        yield '+0.5% of 1 = 1.005 -> 1.01' => [1.0, 0.5, 1.01];
    }

    public function testDiscountDisplayPercentIsTheAbsoluteValue(): void
    {
        self::assertSame(12.5, $this->adjuster->apply(100.0, $this->percent(-12.5))->discountPercentForDisplay);
        self::assertSame(0.0, $this->adjuster->apply(100.0, $this->percent(12.5))->discountPercentForDisplay);
    }

    private function percent(float $percent): ResolvedDiscount
    {
        return new ResolvedDiscount($percent, null, 'campaign');
    }

    private function assertPrice(AdjustedPrice $r, float $final, bool $isDiscount, bool $isMarkup, float $display, float $original): void
    {
        self::assertSame($final, $r->finalPrice, 'finalPrice');
        self::assertSame($isDiscount, $r->isDiscount, 'isDiscount');
        self::assertSame($isMarkup, $r->isMarkup, 'isMarkup');
        self::assertSame($display, $r->discountPercentForDisplay, 'discountPercentForDisplay');
        self::assertSame($original, $r->originalPriceForDisplay, 'originalPriceForDisplay');
    }
}
