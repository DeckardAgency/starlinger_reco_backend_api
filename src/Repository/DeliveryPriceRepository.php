<?php

namespace App\Repository;

use App\Entity\DeliveryPrice;
use App\Entity\DeliveryType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeliveryPrice>
 *
 * @method DeliveryPrice|null find($id, $lockMode = null, $lockVersion = null)
 * @method DeliveryPrice|null findOneBy(array $criteria, array $orderBy = null)
 * @method DeliveryPrice[]    findAll()
 * @method DeliveryPrice[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DeliveryPriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeliveryPrice::class);
    }

    /**
     * Find delivery price by legacy ID (for migration)
     */
    public function findByLegacyId(int $legacyId): ?DeliveryPrice
    {
        return $this->findOneBy(['legacyId' => $legacyId]);
    }

    /**
     * Find prices for a delivery type
     *
     * @return DeliveryPrice[]
     */
    public function findByDeliveryType(DeliveryType $deliveryType): array
    {
        return $this->findBy(['deliveryType' => $deliveryType], ['sizeFrom' => 'ASC']);
    }

    /**
     * Find price for a specific weight and DHL zone
     */
    public function findPriceForWeightAndZone(DeliveryType $deliveryType, float $weight, int $dhlZone): ?DeliveryPrice
    {
        return $this->createQueryBuilder('dp')
            ->where('dp.deliveryType = :deliveryType')
            ->andWhere('dp.dhlZone = :dhlZone')
            ->andWhere('dp.sizeFrom <= :weight')
            ->andWhere('dp.sizeTo >= :weight OR dp.sizeTo IS NULL')
            ->setParameter('deliveryType', $deliveryType)
            ->setParameter('dhlZone', $dhlZone)
            ->setParameter('weight', $weight)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Save a delivery price to the database
     */
    public function save(DeliveryPrice $deliveryPrice, bool $flush = true): void
    {
        $this->getEntityManager()->persist($deliveryPrice);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove a delivery price from the database
     */
    public function remove(DeliveryPrice $deliveryPrice, bool $flush = true): void
    {
        $this->getEntityManager()->remove($deliveryPrice);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
