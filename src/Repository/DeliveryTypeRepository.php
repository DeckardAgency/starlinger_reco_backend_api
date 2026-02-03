<?php

namespace App\Repository;

use App\Entity\DeliveryType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DeliveryType>
 *
 * @method DeliveryType|null find($id, $lockMode = null, $lockVersion = null)
 * @method DeliveryType|null findOneBy(array $criteria, array $orderBy = null)
 * @method DeliveryType[]    findAll()
 * @method DeliveryType[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DeliveryTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DeliveryType::class);
    }

    /**
     * Find delivery type by legacy ID (for migration)
     */
    public function findByLegacyId(int $legacyId): ?DeliveryType
    {
        return $this->findOneBy(['legacyId' => $legacyId]);
    }

    /**
     * Find the default delivery type
     */
    public function findDefault(): ?DeliveryType
    {
        return $this->findOneBy(['useAsDefault' => true, 'isActive' => true]);
    }

    /**
     * Find all active delivery types
     *
     * @return DeliveryType[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true], ['sortOrder' => 'ASC']);
    }

    /**
     * Find delivery types that are actual delivery (not pickup)
     *
     * @return DeliveryType[]
     */
    public function findDeliveryOnly(): array
    {
        return $this->findBy(['isDelivery' => true, 'isActive' => true], ['sortOrder' => 'ASC']);
    }

    /**
     * Save a delivery type to the database
     */
    public function save(DeliveryType $deliveryType, bool $flush = true): void
    {
        $this->getEntityManager()->persist($deliveryType);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove a delivery type from the database
     */
    public function remove(DeliveryType $deliveryType, bool $flush = true): void
    {
        $this->getEntityManager()->remove($deliveryType);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
