<?php

namespace App\Serializer;

use App\Entity\Product;
use App\Service\DiscountResolver;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareInterface;
use Symfony\Component\Serializer\Normalizer\NormalizerAwareTrait;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

class ProductDiscountNormalizer implements NormalizerInterface, NormalizerAwareInterface
{
    use NormalizerAwareTrait;

    private const ALREADY_CALLED_KEY = 'product_discount_normalizer_already_called';

    public function __construct(
        private DiscountResolver $discountResolver,
        private Security $security
    ) {}

    public function normalize(mixed $object, ?string $format = null, array $context = []): array|string|int|float|bool|\ArrayObject|null
    {
        $context[self::ALREADY_CALLED_KEY . spl_object_id($object)] = true;

        /** @var array $data */
        $data = $this->normalizer->normalize($object, $format, $context);

        /** @var Product $object */
        $client = null;
        $user = $this->security->getUser();
        if ($user && method_exists($user, 'getClient')) {
            $client = $user->getClient();
        }

        $resolved = $this->discountResolver->resolveDiscount($object, $client);

        if ($resolved !== null) {
            $basePrice = (float) ($data['price'] ?? $object->getPrice());
            $discountedPrice = $basePrice;

            if ($resolved->fixedPrice !== null) {
                $discountedPrice = min($basePrice, $resolved->fixedPrice);
            } elseif ($resolved->percent > 0) {
                $discountedPrice = round($basePrice * (1 - $resolved->percent / 100), 2);
            }

            $data['hasDiscount'] = true;
            $data['campaignDiscountPercent'] = $resolved->percent;
            $data['discountedPrice'] = round($discountedPrice, 2);
            $data['discountSource'] = $resolved->source;
        } else {
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
