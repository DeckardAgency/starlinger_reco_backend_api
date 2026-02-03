<?php

namespace App\Repository;

use App\Entity\Discount;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Discount>
 *
 * @method Discount|null find($id, $lockMode = null, $lockVersion = null)
 * @method Discount|null findOneBy(array $criteria, array $orderBy = null)
 * @method Discount[]    findAll()
 * @method Discount[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class DiscountRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Discount::class);
    }

    /**
     * Find discount by legacy ID (for migration)
     */
    public function findByLegacyId(int $legacyId): ?Discount
    {
        return $this->findOneBy(['legacyId' => $legacyId]);
    }

    /**
     * Find active discounts
     *
     * @return Discount[]
     */
    public function findActive(): array
    {
        return $this->createQueryBuilder('d')
            ->where('d.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('d.priority', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find currently valid discounts (active and within date range)
     *
     * @return Discount[]
     */
    public function findCurrentlyValid(): array
    {
        $now = new \DateTime();

        return $this->createQueryBuilder('d')
            ->where('d.isActive = :active')
            ->andWhere('(d.dateValidFrom IS NULL OR d.dateValidFrom <= :now)')
            ->andWhere('(d.dateValidTo IS NULL OR d.dateValidTo >= :now)')
            ->setParameter('active', true)
            ->setParameter('now', $now)
            ->orderBy('d.priority', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Save a discount to the database
     */
    public function save(Discount $discount, bool $flush = true): void
    {
        $this->getEntityManager()->persist($discount);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove a discount from the database
     */
    public function remove(Discount $discount, bool $flush = true): void
    {
        $this->getEntityManager()->remove($discount);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
