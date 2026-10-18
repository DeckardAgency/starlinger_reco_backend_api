<?php

namespace App\Service;

/**
 * The one place where a resolved discount becomes a price. Used by the product
 * serializer (shop listing), the order processor (price snapshot) and the
 * client-product endpoints so the arithmetic cannot drift between them.
 *
 * Rules:
 *  - fixed price override: the client pays min(base, fixed). It is a discount
 *    only when it actually lowers the price; it never raises it.
 *  - percentage: round(base * (1 + percent / 100), 2). Negative = discount,
 *    positive = markup.
 *  - no adjustment (null or 0 %): the base price, unchanged.
 */
final class PriceAdjuster
{
    public function apply(float $basePrice, ?ResolvedDiscount $resolved): AdjustedPrice
    {
        if ($resolved === null) {
            return $this->unchanged($basePrice);
        }

        if ($resolved->fixedPrice !== null) {
            $finalPrice = round(min($basePrice, $resolved->fixedPrice), 2);
            if ($finalPrice >= $basePrice || $basePrice <= 0) {
                return $this->unchanged($basePrice);
            }

            return new AdjustedPrice(
                finalPrice: $finalPrice,
                isDiscount: true,
                isMarkup: false,
                discountPercentForDisplay: round(($basePrice - $finalPrice) / $basePrice * 100, 2),
                originalPriceForDisplay: $basePrice,
            );
        }

        if ($resolved->percent == 0.0) {
            return $this->unchanged($basePrice);
        }

        $finalPrice = round($basePrice * (1 + $resolved->percent / 100), 2);

        if ($resolved->percent < 0) {
            return new AdjustedPrice(
                finalPrice: $finalPrice,
                isDiscount: true,
                isMarkup: false,
                discountPercentForDisplay: abs($resolved->percent),
                originalPriceForDisplay: $basePrice,
            );
        }

        return new AdjustedPrice(
            finalPrice: $finalPrice,
            isDiscount: false,
            isMarkup: true,
            discountPercentForDisplay: 0.0,
            originalPriceForDisplay: $finalPrice,
        );
    }

    private function unchanged(float $basePrice): AdjustedPrice
    {
        return new AdjustedPrice(
            finalPrice: $basePrice,
            isDiscount: false,
            isMarkup: false,
            discountPercentForDisplay: 0.0,
            originalPriceForDisplay: $basePrice,
        );
    }
}
