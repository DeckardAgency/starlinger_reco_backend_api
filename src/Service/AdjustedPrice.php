<?php

namespace App\Service;

/**
 * Result of PriceAdjuster::apply(): the price a client actually pays plus what
 * to show around it.
 *
 *  - discount (price went down): isDiscount, discountPercentForDisplay > 0 (the
 *    positive number the shop renders as "-X%"), originalPriceForDisplay = the
 *    price before the discount.
 *  - markup (price went up): isMarkup; the raised price is simply the client's
 *    price, so originalPriceForDisplay = finalPrice and no discount is shown.
 *  - nothing applied: neither flag, originalPriceForDisplay = finalPrice = base.
 */
final class AdjustedPrice
{
    public function __construct(
        public readonly float $finalPrice,
        public readonly bool $isDiscount,
        public readonly bool $isMarkup,
        public readonly float $discountPercentForDisplay,
        public readonly float $originalPriceForDisplay,
    ) {}
}
