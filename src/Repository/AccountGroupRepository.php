<?php

namespace App\Repository;

use App\Entity\AccountGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<AccountGroup>
 *
 * @method AccountGroup|null find($id, $lockMode = null, $lockVersion = null)
 * @method AccountGroup|null findOneBy(array $criteria, array $orderBy = null)
 * @method AccountGroup[]    findAll()
 * @method AccountGroup[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AccountGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, AccountGroup::class);
    }

    /**
     * Find all active account groups
     *
     * @return AccountGroup[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true], ['name' => 'ASC']);
    }

    public function save(AccountGroup $accountGroup, bool $flush = true): void
    {
        $this->getEntityManager()->persist($accountGroup);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    public function remove(AccountGroup $accountGroup, bool $flush = true): void
    {
        $this->getEntityManager()->remove($accountGroup);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
