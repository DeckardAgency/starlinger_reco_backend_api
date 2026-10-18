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
