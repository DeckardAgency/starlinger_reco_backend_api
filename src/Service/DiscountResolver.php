<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Discount;
use App\Entity\Product;
use App\Entity\ProductDiscount;
use Doctrine\ORM\EntityManagerInterface;

class ResolvedDiscount
{
    public function __construct(
        public readonly float $percent,
        public readonly ?float $fixedPrice,
        public readonly string $source // 'campaign' or 'product'
    ) {}
}

class DiscountResolver
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Request-scoped caches. resolveDiscount() is invoked once per product while
     * serializing a product collection (see ProductDiscountNormalizer), so the
     * active-discount list and the set of discount ids that have product links
     * are identical for every product in a single response. Resolving them once
     * turns the shop listing from O(products × discounts) queries into O(1).
     */
    private ?array $activeDiscounts = null;
    private ?array $linkedDiscountIds = null;
    private ?array $productSpecificData = null;

    /**
     * Resolve the best applicable campaign discount for a product + client combination.
     */
    public function resolveDiscount(Product $product, ?Client $client): ?ResolvedDiscount
    {
        return $this->findCampaignDiscount($product, $client);
    }

    private function findCampaignDiscount(Product $product, ?Client $client): ?ResolvedDiscount
    {
        $now = new \DateTime();

        // 1. Check product-specific campaign discounts (ProductDiscount links)
        $productSpecific = $this->findProductSpecificCampaign($product, $client, $now);
        if ($productSpecific !== null) {
            return $productSpecific;
        }

        // 2. Check client-wide campaign discounts (no products linked = applies to all products)
        return $this->findClientWideCampaign($product, $client, $now);
    }

    private function findProductSpecificCampaign(Product $product, ?Client $client, \DateTime $now): ?ResolvedDiscount
    {
        $data = $this->getProductSpecificData($now);

        $productDiscounts = $data['pdsByProduct'][$product->getId()] ?? [];
        if (empty($productDiscounts)) {
            return null;
        }

        // Iterate discounts in global priority order, considering only those linked to
        // this product (identical selection to the former per-product query).
        foreach ($data['order'] as $discountId) {
            if (!isset($productDiscounts[$discountId])) {
                continue;
            }
            $discount = $data['discountsById'][$discountId];
            if (!$this->isDiscountApplicableToClient($discount, $client)) {
                continue;
            }

            if (!$this->discountMatchesProductType($discount, $product)) {
                continue;
            }

            $pd = $productDiscounts[$discountId] ?? null;
            if (!$pd) {
                continue;
            }

            if ($pd->getDateValidFrom() !== null && $pd->getDateValidFrom() > $now) {
                continue;
            }
            if ($pd->getDateValidTo() !== null && $pd->getDateValidTo() < $now) {
                continue;
            }

            $fixedPrice = null;
            $percent = 0.0;

            if ($pd->getDiscountPriceBase() !== null) {
                $fixedPrice = (float) $pd->getDiscountPriceBase();
            }

            if ($pd->getRebate() !== null && (float) $pd->getRebate() > 0) {
                $percent = (float) $pd->getRebate();
            }

            if ($fixedPrice === null && $percent <= 0 && $discount->getDiscountPercent() !== null) {
                $percent = (float) $discount->getDiscountPercent();
            }

            if ($fixedPrice !== null || $percent > 0) {
                return new ResolvedDiscount($percent, $fixedPrice, 'campaign');
            }
        }

        return null;
    }

    /**
     * All active, in-date product-specific discount links, loaded in ONE query and
     * indexed by product id, cached for the request. Previously this ran a query per
     * product while serializing a product/order collection (the N+1). Returns:
     *   'pdsByProduct'  => [productId => [discountId => ProductDiscount]]
     *   'discountsById' => [discountId => Discount]
     *   'order'         => [discountId, ...] in priority order (DESC), de-duplicated
     *
     * @return array{pdsByProduct: array<int, array<int, ProductDiscount>>, discountsById: array<int, Discount>, order: int[]}
     */
    private function getProductSpecificData(\DateTime $now): array
    {
        if ($this->productSpecificData !== null) {
            return $this->productSpecificData;
        }

        $results = $this->entityManager->createQueryBuilder()
            ->select('pd', 'd')
            ->from(ProductDiscount::class, 'pd')
            ->join(Discount::class, 'd', 'WITH', 'pd.discountId = d.id')
            ->where('d.isActive = true')
            ->andWhere('(d.dateValidFrom IS NULL OR d.dateValidFrom <= :now)')
            ->andWhere('(d.dateValidTo IS NULL OR d.dateValidTo >= :now)')
            ->setParameter('now', $now)
            ->orderBy('d.priority', 'DESC')
            ->addOrderBy('d.id', 'ASC')
            ->getQuery()
            ->getResult();

        $pdsByProduct = [];
        $discountsById = [];
        $order = [];
        foreach ($results as $entity) {
            if ($entity instanceof ProductDiscount) {
                $pdsByProduct[$entity->getProductId()][$entity->getDiscountId()] = $entity;
            } elseif ($entity instanceof Discount) {
                if (!isset($discountsById[$entity->getId()])) {
                    $order[] = $entity->getId();
                }
                $discountsById[$entity->getId()] = $entity;
            }
        }

        return $this->productSpecificData = [
            'pdsByProduct' => $pdsByProduct,
            'discountsById' => $discountsById,
            'order' => $order,
        ];
    }

    /**
     * Find discounts that target this client (or their account group) but have NO products linked.
     * These apply to ALL products for that client.
     */
    private function findClientWideCampaign(Product $product, ?Client $client, \DateTime $now): ?ResolvedDiscount
    {
        if ($client === null) {
            return null;
        }

        // Get all active discounts within date range (cached per request)
        $discounts = $this->getActiveDiscounts($now);
        $linkedDiscountIds = $this->getLinkedDiscountIds();

        foreach ($discounts as $discount) {
            // Skip discounts that have products linked (those are product-specific)
            if (isset($linkedDiscountIds[$discount->getId()])) {
                continue;
            }

            // This discount has no products — check if it targets this client
            if (!$this->isDiscountApplicableToClient($discount, $client)) {
                continue;
            }

            if (!$this->discountMatchesProductType($discount, $product)) {
                continue;
            }

            $percent = $discount->getDiscountPercent();
            if ($percent !== null && (float) $percent > 0) {
                return new ResolvedDiscount((float) $percent, null, 'campaign');
            }
        }

        return null;
    }

    /**
     * Active, in-date discounts. Cached for the lifetime of the request so a
     * product collection doesn't re-run this query for every product.
     *
     * @return Discount[]
     */
    private function getActiveDiscounts(\DateTime $now): array
    {
        if ($this->activeDiscounts === null) {
            $this->activeDiscounts = $this->entityManager->createQueryBuilder()
                ->select('d')
                ->from(Discount::class, 'd')
                ->where('d.isActive = true')
                ->andWhere('(d.dateValidFrom IS NULL OR d.dateValidFrom <= :now)')
                ->andWhere('(d.dateValidTo IS NULL OR d.dateValidTo >= :now)')
                ->setParameter('now', $now)
                ->orderBy('d.priority', 'DESC')
                ->addOrderBy('d.id', 'ASC')
                ->getQuery()
                ->getResult();
        }

        return $this->activeDiscounts;
    }

    /**
     * Set of discount ids that have at least one ProductDiscount link, fetched in
     * a single query. Replaces a per-discount COUNT query inside the resolve loop.
     * Returned as a map (id => true) for O(1) membership checks.
     *
     * @return array<int, true>
     */
    private function getLinkedDiscountIds(): array
    {
        if ($this->linkedDiscountIds === null) {
            $rows = $this->entityManager->createQueryBuilder()
                ->select('DISTINCT pd.discountId')
                ->from(ProductDiscount::class, 'pd')
                ->getQuery()
                ->getScalarResult();

            $this->linkedDiscountIds = [];
            foreach ($rows as $row) {
                $this->linkedDiscountIds[(int) $row['discountId']] = true;
            }
        }

        return $this->linkedDiscountIds;
    }

    /**
     * If the discount has productTypes set, the product's productType must be in that list.
     * Empty/null productTypes means no restriction (applies to all product types).
     */
    private function discountMatchesProductType(Discount $discount, Product $product): bool
    {
        $allowed = $discount->getProductTypes();
        if (empty($allowed)) {
            return true;
        }

        $type = $product->getProductType();
        if ($type === null) {
            return false;
        }

        return in_array($type, $allowed, true);
    }

    private function isDiscountApplicableToClient(Discount $discount, ?Client $client): bool
    {
        $targetClients = $discount->getClients();
        $targetAccountGroups = $discount->getAccountGroups();

        // If no targets specified, discount is global (applies to everyone)
        if ($targetClients->isEmpty() && $targetAccountGroups->isEmpty()) {
            return true;
        }

        if ($client === null) {
            return false;
        }

        // Check if client is directly targeted
        foreach ($targetClients as $targetClient) {
            if ($targetClient->getId() === $client->getId()) {
                return true;
            }
        }

        // Check if client's account group is targeted
        $clientAccountGroup = $client->getAccountGroup();
        if ($clientAccountGroup !== null) {
            foreach ($targetAccountGroups as $targetGroup) {
                if ($targetGroup->getId() === $clientAccountGroup->getId()) {
                    return true;
                }
            }
        }

        return false;
    }

}
