<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Discount;
use App\Entity\Product;
use App\Entity\ProductDiscount;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Picks the price adjustments for a product + client: at most ONE markup and at
 * most ONE discount, which apply together (Nov 2026). Only active discounts that
 * list the client are considered (guests and unlisted clients get nothing).
 * Precedence, separately for the markup and for the discount: a discount linked
 * to the product (ProductDiscount) beats a client-wide one (no product links);
 * within a tier the lowest id wins. A value is a fixed price override (always a
 * discount) or a signed percentage (negative = discount, positive = markup) —
 * PriceAdjuster turns the pair into a price.
 */
class DiscountResolver implements ResetInterface
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    /**
     * Clears the request-scoped caches. Called by the services resetter between
     * messages in long-running Messenger workers so stale discounts aren't reused.
     */
    public function reset(): void
    {
        $this->activeDiscounts = null;
        $this->linkedDiscountIds = null;
        $this->productSpecificData = null;
    }

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
     * Resolve the markup and the discount that apply to a product + client. The
     * candidates are walked in precedence order (product-specific links first, then
     * client-wide, each by id) and the first markup and the first discount win.
     */
    public function resolveAdjustments(Product $product, ?Client $client): ResolvedAdjustments
    {
        if ($client === null) {
            return ResolvedAdjustments::none();
        }

        $markup = null;
        $discount = null;
        foreach ($this->candidates($product, $client) as $candidate) {
            $isMarkup = $candidate->fixedPrice === null && $candidate->percent > 0;
            if ($isMarkup) {
                $markup ??= $candidate;
            } else {
                $discount ??= $candidate;
            }
            if ($markup !== null && $discount !== null) {
                break;
            }
        }

        return new ResolvedAdjustments($markup, $discount);
    }

    /**
     * Every applicable adjustment in precedence order.
     *
     * @return iterable<ResolvedDiscount>
     */
    private function candidates(Product $product, Client $client): iterable
    {
        // 1. Discounts linked to this product (ProductDiscount links)
        yield from $this->productSpecificCandidates($product, $client);

        // 2. Client-wide discounts (no products linked = applies to all products)
        yield from $this->clientWideCandidates($product, $client);
    }

    /** @return iterable<ResolvedDiscount> */
    private function productSpecificCandidates(Product $product, Client $client): iterable
    {
        $data = $this->getProductSpecificData();

        $productDiscounts = $data['pdsByProduct'][$product->getId()] ?? [];
        if (empty($productDiscounts)) {
            return;
        }

        // Iterate discounts in id order, considering only those linked to this product.
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

            /** @var ProductDiscount $pd */
            $pd = $productDiscounts[$discountId];

            // Value precedence: fixed price override → per-link rebate → the discount's percent.
            if ($pd->getDiscountPriceBase() !== null) {
                yield new ResolvedDiscount(0.0, (float) $pd->getDiscountPriceBase(), 'campaign');
                continue;
            }

            $percent = (float) ($pd->getRebate() ?? 0);
            if ($percent == 0.0) {
                $percent = (float) ($discount->getAdjustmentPercent() ?? 0);
            }

            if ($percent != 0.0) {
                yield new ResolvedDiscount($percent, null, 'campaign');
            }
        }
    }

    /**
     * All product links of active discounts, loaded in ONE query and indexed by
     * product id, cached for the request. Previously this ran a query per product
     * while serializing a product/order collection (the N+1). Returns:
     *   'pdsByProduct'  => [productId => [discountId => ProductDiscount]]
     *   'discountsById' => [discountId => Discount]
     *   'order'         => [discountId, ...] by id ASC, de-duplicated
     *
     * @return array{pdsByProduct: array<int, array<int, ProductDiscount>>, discountsById: array<int, Discount>, order: int[]}
     */
    private function getProductSpecificData(): array
    {
        if ($this->productSpecificData !== null) {
            return $this->productSpecificData;
        }

        $results = $this->entityManager->createQueryBuilder()
            ->select('pd', 'd')
            ->from(ProductDiscount::class, 'pd')
            ->join(Discount::class, 'd', 'WITH', 'pd.discountId = d.id')
            ->where('d.isActive = true')
            ->orderBy('d.id', 'ASC')
            ->addOrderBy('pd.id', 'ASC')
            ->getQuery()
            ->getResult();

        $pdsByProduct = [];
        $discountsById = [];
        $order = [];
        foreach ($results as $entity) {
            if ($entity instanceof ProductDiscount) {
                // One link per (product, discount) is expected; keep the first (lowest id).
                $pdsByProduct[$entity->getProductId()][$entity->getDiscountId()] ??= $entity;
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
     * Discounts that target this client but have NO products linked: they apply to
     * ALL products (within productTypes, if set) for that client.
     *
     * @return iterable<ResolvedDiscount>
     */
    private function clientWideCandidates(Product $product, Client $client): iterable
    {
        $discounts = $this->getActiveDiscounts();
        $linkedDiscountIds = $this->getLinkedDiscountIds();

        foreach ($discounts as $discount) {
            // Skip discounts that have products linked (those are product-specific)
            if (isset($linkedDiscountIds[$discount->getId()])) {
                continue;
            }

            if (!$this->isDiscountApplicableToClient($discount, $client)) {
                continue;
            }

            if (!$this->discountMatchesProductType($discount, $product)) {
                continue;
            }

            $percent = (float) ($discount->getAdjustmentPercent() ?? 0);
            if ($percent != 0.0) {
                yield new ResolvedDiscount($percent, null, 'campaign');
            }
        }
    }

    /**
     * Active discounts by id ASC. Cached for the lifetime of the request so a
     * product collection doesn't re-run this query for every product.
     *
     * @return Discount[]
     */
    private function getActiveDiscounts(): array
    {
        if ($this->activeDiscounts === null) {
            $this->activeDiscounts = $this->entityManager->createQueryBuilder()
                ->select('d')
                ->from(Discount::class, 'd')
                ->where('d.isActive = true')
                ->orderBy('d.id', 'ASC')
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

    /**
     * A discount applies to exactly the clients it lists. No clients = nobody.
     */
    private function isDiscountApplicableToClient(Discount $discount, Client $client): bool
    {
        foreach ($discount->getClients() as $targetClient) {
            if ($targetClient->getId() === $client->getId()) {
                return true;
            }
        }

        return false;
    }
}
