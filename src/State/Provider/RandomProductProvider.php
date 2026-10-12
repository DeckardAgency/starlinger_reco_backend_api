<?php

namespace App\State\Provider;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use App\Entity\Product;
use App\Repository\ProductRepository;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * GET /products/random?limit=N — a random selection of parts that are active
 * and in stock (partner requirement: "5 randomly selected products currently
 * in stock"). Picks random ids in PHP (the catalogue is small) instead of
 * ORDER BY RAND(); returns plain Product entities, not paginated.
 */
final class RandomProductProvider implements ProviderInterface
{
    public const DEFAULT_LIMIT = 5;
    public const MAX_LIMIT = 20;

    public function __construct(
        private readonly ProductRepository $products,
        private readonly RequestStack $requestStack,
    ) {
    }

    /** @return list<Product> */
    public function provide(Operation $operation, array $uriVariables = [], array $context = []): array
    {
        $requested = $this->requestStack->getCurrentRequest()?->query->getInt('limit', self::DEFAULT_LIMIT) ?? self::DEFAULT_LIMIT;
        $limit = max(1, min(self::MAX_LIMIT, $requested));

        /** @var list<int> $ids */
        $ids = $this->products->createQueryBuilder('p')
            ->select('p.id')
            ->where('p.isActive = true')
            ->andWhere('p.inStock = true')
            ->getQuery()
            ->getSingleColumnResult();

        if ($ids === []) {
            return [];
        }

        shuffle($ids);
        $picked = array_slice($ids, 0, $limit);

        $byId = [];
        foreach ($this->products->findBy(['id' => $picked]) as $product) {
            $byId[(int) $product->getId()] = $product;
        }

        // Keep the random order (findBy returns rows in table order).
        $result = [];
        foreach ($picked as $id) {
            if (isset($byId[(int) $id])) {
                $result[] = $byId[(int) $id];
            }
        }

        return $result;
    }
}
