<?php

namespace App\Service;

/**
 * What DiscountResolver found for one product + client: at most ONE markup and
 * at most ONE discount (a negative percentage or a fixed price). Both can apply
 * to the same customer at the same time (Nov 2026); PriceAdjuster composes them —
 * markup first, the discount on the marked-up price.
 */
final class ResolvedAdjustments
{
    public function __construct(
        public readonly ?ResolvedDiscount $markup,
        public readonly ?ResolvedDiscount $discount,
    ) {}

    public static function none(): self
    {
        return new self(null, null);
    }

    public function isEmpty(): bool
    {
        return $this->markup === null && $this->discount === null;
    }
}
