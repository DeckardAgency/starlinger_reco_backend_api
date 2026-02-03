<?php

namespace App\Repository;

use App\Entity\TaxType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TaxType>
 *
 * @method TaxType|null find($id, $lockMode = null, $lockVersion = null)
 * @method TaxType|null findOneBy(array $criteria, array $orderBy = null)
 * @method TaxType[]    findAll()
 * @method TaxType[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class TaxTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TaxType::class);
    }

    /**
     * Find tax type by legacy ID (for migration)
     */
    public function findByLegacyId(int $legacyId): ?TaxType
    {
        return $this->findOneBy(['legacyId' => $legacyId]);
    }

    /**
     * Find tax type by remote code
     */
    public function findByRemoteCode(string $remoteCode): ?TaxType
    {
        return $this->findOneBy(['remoteCode' => $remoteCode]);
    }

    /**
     * Find all active tax types
     *
     * @return TaxType[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true], ['percent' => 'ASC']);
    }

    /**
     * Save a tax type to the database
     */
    public function save(TaxType $taxType, bool $flush = true): void
    {
        $this->getEntityManager()->persist($taxType);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove a tax type from the database
     */
    public function remove(TaxType $taxType, bool $flush = true): void
    {
        $this->getEntityManager()->remove($taxType);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
