<?php

namespace App\Repository;

use App\Entity\PackagingPrice;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PackagingPrice>
 *
 * @method PackagingPrice|null find($id, $lockMode = null, $lockVersion = null)
 * @method PackagingPrice|null findOneBy(array $criteria, array $orderBy = null)
 * @method PackagingPrice[]    findAll()
 * @method PackagingPrice[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PackagingPriceRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PackagingPrice::class);
    }

    /**
     * Find packaging price by legacy ID (for migration)
     */
    public function findByLegacyId(int $legacyId): ?PackagingPrice
    {
        return $this->findOneBy(['legacyId' => $legacyId]);
    }

    /**
     * Find price for a specific size range
     */
    public function findPriceForSize(float $size): ?PackagingPrice
    {
        return $this->createQueryBuilder('pp')
            ->where('pp.sizeFrom <= :size')
            ->andWhere('pp.sizeTo >= :size OR pp.sizeTo IS NULL')
            ->setParameter('size', $size)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Save a packaging price to the database
     */
    public function save(PackagingPrice $packagingPrice, bool $flush = true): void
    {
        $this->getEntityManager()->persist($packagingPrice);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove a packaging price from the database
     */
    public function remove(PackagingPrice $packagingPrice, bool $flush = true): void
    {
        $this->getEntityManager()->remove($packagingPrice);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
