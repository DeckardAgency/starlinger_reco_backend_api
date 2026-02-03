<?php

namespace App\Repository;

use App\Entity\ImportManualType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportManualType>
 *
 * @method ImportManualType|null find($id, $lockMode = null, $lockVersion = null)
 * @method ImportManualType|null findOneBy(array $criteria, array $orderBy = null)
 * @method ImportManualType[]    findAll()
 * @method ImportManualType[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ImportManualTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportManualType::class);
    }

    /**
     * Find type by legacy ID (for migration)
     */
    public function findByLegacyId(int $legacyId): ?ImportManualType
    {
        return $this->findOneBy(['legacyId' => $legacyId]);
    }

    /**
     * Find type by name
     */
    public function findByName(string $name): ?ImportManualType
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * Find type by manual type code
     */
    public function findByCode(string $code): ?ImportManualType
    {
        return $this->findOneBy(['manualTypeCode' => $code]);
    }

    /**
     * Save a type to the database
     */
    public function save(ImportManualType $type, bool $flush = true): void
    {
        $this->getEntityManager()->persist($type);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove a type from the database
     */
    public function remove(ImportManualType $type, bool $flush = true): void
    {
        $this->getEntityManager()->remove($type);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
