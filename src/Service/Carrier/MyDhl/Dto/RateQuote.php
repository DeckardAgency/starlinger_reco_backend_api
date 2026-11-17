<?php

namespace App\Service\Carrier\MyDhl\Dto;

/**
 * One product offered by GET /rates for a given route, weight and date.
 */
final class RateQuote
{
    public function __construct(
        public readonly string $productCode,
        public readonly string $productName,
        /** Price in the account's billing currency; null when DHL returned no price (agreement-only products). */
        public readonly ?float $price,
        public readonly ?string $currency,
        public readonly ?\DateTimeImmutable $estimatedDeliveryAt,
        /** Latest local time a courier pickup can be booked for the planned date. */
        public readonly ?\DateTimeImmutable $pickupCutoffAt,
        /** Product is only available with a separate DHL agreement. */
        public readonly bool $requiresAgreement,
    ) {
    }

    public static function fromArray(array $product): self
    {
        $billing = null;
        foreach ($product['totalPrice'] ?? [] as $total) {
            if (($total['currencyType'] ?? null) === 'BILLC') {
                $billing = $total;
                break;
            }
        }
        $price = isset($billing['price']) ? (float) $billing['price'] : null;

        return new self(
            productCode: (string) ($product['productCode'] ?? ''),
            productName: (string) ($product['productName'] ?? ''),
            // DHL returns 0 for products it can't price on this account
            price: $price > 0 ? $price : null,
            currency: $billing['priceCurrency'] ?? null,
            estimatedDeliveryAt: self::date($product['deliveryCapabilities']['estimatedDeliveryDateAndTime'] ?? null),
            pickupCutoffAt: self::date($product['pickupCapabilities']['localCutoffDateAndTime'] ?? null),
            requiresAgreement: (bool) ($product['isCustomerAgreement'] ?? false),
        );
    }

    private static function date(?string $value): ?\DateTimeImmutable
    {
        if (!$value) {
            return null;
        }
        try {
            return new \DateTimeImmutable($value);
        } catch (\Exception) {
            return null;
        }
    }
}
