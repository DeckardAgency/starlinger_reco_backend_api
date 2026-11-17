<?php

namespace App\Service;

/**
 * The adjustment DiscountResolver picked for one product + client. Exactly one
 * of the two values is meaningful: a fixed price override (fixedPrice set,
 * percent 0) or a signed percentage (negative = discount, positive = markup).
 * PriceAdjuster turns it into a price.
 */
final class ResolvedDiscount
{
    public function __construct(
        public readonly float $percent,
        public readonly ?float $fixedPrice,
        public readonly string $source // 'campaign'
    ) {}
}
