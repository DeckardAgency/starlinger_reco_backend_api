<?php

namespace App\Repository;

use App\Entity\FuelSurcharge;
use App\Entity\DeliveryType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<FuelSurcharge>
 *
 * @method FuelSurcharge|null find($id, $lockMode = null, $lockVersion = null)
 * @method FuelSurcharge|null findOneBy(array $criteria, array $orderBy = null)
 * @method FuelSurcharge[]    findAll()
 * @method FuelSurcharge[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class FuelSurchargeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, FuelSurcharge::class);
    }

    /**
     * Find fuel surcharge by legacy ID (for migration)
     */
    public function findByLegacyId(int $legacyId): ?FuelSurcharge
    {
        return $this->findOneBy(['legacyId' => $legacyId]);
    }

    /**
     * Find fuel surcharges for a delivery type
     *
     * @return FuelSurcharge[]
     */
    public function findByDeliveryType(DeliveryType $deliveryType): array
    {
        return $this->findBy(['deliveryType' => $deliveryType], ['date' => 'DESC']);
    }

    /**
     * Find the latest fuel surcharge for a delivery type
     */
    public function findLatestForDeliveryType(DeliveryType $deliveryType): ?FuelSurcharge
    {
        return $this->createQueryBuilder('fs')
            ->where('fs.deliveryType = :deliveryType')
            ->andWhere('fs.date <= :today')
            ->setParameter('deliveryType', $deliveryType)
            ->setParameter('today', new \DateTime())
            ->orderBy('fs.date', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Save a fuel surcharge to the database
     */
    public function save(FuelSurcharge $fuelSurcharge, bool $flush = true): void
    {
        $this->getEntityManager()->persist($fuelSurcharge);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove a fuel surcharge from the database
     */
    public function remove(FuelSurcharge $fuelSurcharge, bool $flush = true): void
    {
        $this->getEntityManager()->remove($fuelSurcharge);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
