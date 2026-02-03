<?php

namespace App\Repository;

use App\Entity\ImportManualStatus;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportManualStatus>
 *
 * @method ImportManualStatus|null find($id, $lockMode = null, $lockVersion = null)
 * @method ImportManualStatus|null findOneBy(array $criteria, array $orderBy = null)
 * @method ImportManualStatus[]    findAll()
 * @method ImportManualStatus[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ImportManualStatusRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportManualStatus::class);
    }

    /**
     * Find status by legacy ID (for migration)
     */
    public function findByLegacyId(int $legacyId): ?ImportManualStatus
    {
        return $this->findOneBy(['legacyId' => $legacyId]);
    }

    /**
     * Find status by name
     */
    public function findByName(string $name): ?ImportManualStatus
    {
        return $this->findOneBy(['name' => $name]);
    }

    /**
     * Save a status to the database
     */
    public function save(ImportManualStatus $status, bool $flush = true): void
    {
        $this->getEntityManager()->persist($status);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove a status from the database
     */
    public function remove(ImportManualStatus $status, bool $flush = true): void
    {
        $this->getEntityManager()->remove($status);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
