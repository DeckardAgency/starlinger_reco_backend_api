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
     * Resolve the best applicable discount for a product + client combination.
     * Campaign discounts take priority over product-level discounts.
     */
    public function resolveDiscount(Product $product, ?Client $client): ?ResolvedDiscount
    {
        // 1. Check campaign discounts (highest priority)
        $campaignDiscount = $this->findCampaignDiscount($product, $client);
        if ($campaignDiscount !== null) {
            return $campaignDiscount;
        }

        // 2. Fall back to product-level discount
        return $this->findProductDiscount($product);
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
        return $this->findClientWideCampaign($client, $now);
    }

    private function findProductSpecificCampaign(Product $product, ?Client $client, \DateTime $now): ?ResolvedDiscount
    {
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('pd', 'd')
            ->from(ProductDiscount::class, 'pd')
            ->join(Discount::class, 'd', 'WITH', 'pd.discountId = d.id')
            ->where('pd.productId = :productId')
            ->andWhere('d.isActive = true')
            ->andWhere('(d.dateValidFrom IS NULL OR d.dateValidFrom <= :now)')
            ->andWhere('(d.dateValidTo IS NULL OR d.dateValidTo >= :now)')
            ->setParameter('productId', $product->getId())
            ->setParameter('now', $now)
            ->orderBy('d.priority', 'DESC')
            ->addOrderBy('d.id', 'ASC');

        $results = $qb->getQuery()->getResult();

        $productDiscounts = [];
        $discounts = [];
        foreach ($results as $entity) {
            if ($entity instanceof ProductDiscount) {
                $productDiscounts[$entity->getDiscountId()] = $entity;
            } elseif ($entity instanceof Discount) {
                $discounts[$entity->getId()] = $entity;
            }
        }

        foreach ($discounts as $discountId => $discount) {
            if (!$this->isDiscountApplicableToClient($discount, $client)) {
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
     * Find discounts that target this client (or their account group) but have NO products linked.
     * These apply to ALL products for that client.
     */
    private function findClientWideCampaign(?Client $client, \DateTime $now): ?ResolvedDiscount
    {
        if ($client === null) {
            return null;
        }

        // Get all active discounts within date range
        $qb = $this->entityManager->createQueryBuilder();
        $qb->select('d')
            ->from(Discount::class, 'd')
            ->where('d.isActive = true')
            ->andWhere('(d.dateValidFrom IS NULL OR d.dateValidFrom <= :now)')
            ->andWhere('(d.dateValidTo IS NULL OR d.dateValidTo >= :now)')
            ->setParameter('now', $now)
            ->orderBy('d.priority', 'DESC')
            ->addOrderBy('d.id', 'ASC');

        $discounts = $qb->getQuery()->getResult();

        foreach ($discounts as $discount) {
            // Skip discounts that have products linked (those are product-specific)
            $productCount = $this->entityManager->createQueryBuilder()
                ->select('COUNT(pd.id)')
                ->from(ProductDiscount::class, 'pd')
                ->where('pd.discountId = :discountId')
                ->setParameter('discountId', $discount->getId())
                ->getQuery()
                ->getSingleScalarResult();

            if ((int) $productCount > 0) {
                continue;
            }

            // This discount has no products — check if it targets this client
            if (!$this->isDiscountApplicableToClient($discount, $client)) {
                continue;
            }

            $percent = $discount->getDiscountPercent();
            if ($percent !== null && (float) $percent > 0) {
                return new ResolvedDiscount((float) $percent, null, 'campaign');
            }
        }

        return null;
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

    private function findProductDiscount(Product $product): ?ResolvedDiscount
    {
        $discountPercent = $product->getDiscountPercent();
        $discountPrice = $product->getDiscountPrice();

        if ($discountPrice !== null && $discountPrice > 0) {
            // Fixed discounted price — calculate percent from base price
            $basePrice = $product->getPrice();
            $percent = 0.0;
            if ($basePrice > 0 && $discountPrice < $basePrice) {
                $percent = (($basePrice - $discountPrice) / $basePrice) * 100;
            }
            return new ResolvedDiscount(round($percent, 2), $discountPrice, 'product');
        }

        if ($discountPercent !== null && $discountPercent > 0) {
            return new ResolvedDiscount($discountPercent, null, 'product');
        }

        return null;
    }
}
