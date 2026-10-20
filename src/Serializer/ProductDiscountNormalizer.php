<?php

namespace App\Serializer;

use App\Entity\Product;
use App\Service\DiscountResolver;
use App\Service\PriceAdjuster;
use App\Service\PricingClientResolver;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Adds the current client's price adjustment to every serialized Product:
 * `hasDiscount`, `campaignDiscountPercent` (positive, rendered as "-X%"),
 * `discountedPrice`, `discountSource`. A markup replaces `price` with the raised
 * price and leaves the discount fields empty. Math lives in PriceAdjuster.
 *
 * "Current client" is the user's own company, or the client named by
 * `?forClient=<id>` (client agents / admins) — see PricingClientResolver, which
 * also throws the 400/403/404 for a bad or unauthorised value.
 */
class ProductDiscountNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED_KEY = 'product_discount_normalizer_already_called';

    public function __construct(
        private DiscountResolver $discountResolver,
        private PriceAdjuster $priceAdjuster,
        private PricingClientResolver $pricingClientResolver
    ) {}

    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $context[self::ALREADY_CALLED_KEY . spl_object_id($object)] = true;

        /** @var array $data */
        $data = $this->normalizer->normalize($object, $format, $context);

        /** @var Product $object */
        $client = $this->pricingClientResolver->resolve();

        $resolved = $this->discountResolver->resolveDiscount($object, $client);
        $basePrice = (float) ($data['price'] ?? $object->getPrice());
        $adjusted = $this->priceAdjuster->apply($basePrice, $resolved);

        if ($adjusted->isDiscount) {
            // Shown as: old price, "-X%", new price.
            $data['hasDiscount'] = true;
            $data['campaignDiscountPercent'] = $adjusted->discountPercentForDisplay;
            $data['discountedPrice'] = $adjusted->finalPrice;
            $data['discountSource'] = $resolved?->source;
        } else {
            // A markup is simply the client's price: no discount fields are set.
            if ($adjusted->isMarkup && array_key_exists('price', $data)) {
                $data['price'] = $adjusted->finalPrice;
            }
            $data['hasDiscount'] = false;
            $data['campaignDiscountPercent'] = 0;
            $data['discountedPrice'] = null;
            $data['discountSource'] = null;
        }

        return $data;
    }

    public function supportsNormalization(mixed $data, ?string $format = null, array $context = []): bool
    {
        if (!$data instanceof Product) {
            return false;
        }

        if (isset($context[self::ALREADY_CALLED_KEY . spl_object_id($data)])) {
            return false;
        }

        return true;
    }

    public function getSupportedTypes(?string $format): array
    {
        return [
            Product::class => false,
        ];
    }
}
