<?php

namespace App\Repository;

use App\Entity\ContactTitle;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ContactTitle>
 *
 * @method ContactTitle|null find($id, $lockMode = null, $lockVersion = null)
 * @method ContactTitle|null findOneBy(array $criteria, array $orderBy = null)
 * @method ContactTitle[]    findAll()
 * @method ContactTitle[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ContactTitleRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ContactTitle::class);
    }
}
