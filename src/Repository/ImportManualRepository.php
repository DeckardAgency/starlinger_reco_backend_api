<?php

namespace App\Repository;

use App\Entity\ImportManual;
use App\Entity\ImportManualStatus;
use App\Entity\ImportManualType;
use App\Entity\User;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ImportManual>
 *
 * @method ImportManual|null find($id, $lockMode = null, $lockVersion = null)
 * @method ImportManual|null findOneBy(array $criteria, array $orderBy = null)
 * @method ImportManual[]    findAll()
 * @method ImportManual[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ImportManualRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ImportManual::class);
    }

    /**
     * Find import manual by legacy ID (for migration)
     */
    public function findByLegacyId(int $legacyId): ?ImportManual
    {
        return $this->findOneBy(['legacyId' => $legacyId]);
    }

    /**
     * Find imports by status
     *
     * @return ImportManual[]
     */
    public function findByStatus(ImportManualStatus $status): array
    {
        return $this->findBy(['status' => $status], ['createdAt' => 'DESC']);
    }

    /**
     * Find imports by type
     *
     * @return ImportManual[]
     */
    public function findByType(ImportManualType $type): array
    {
        return $this->findBy(['type' => $type], ['createdAt' => 'DESC']);
    }

    /**
     * Find imports by user
     *
     * @return ImportManual[]
     */
    public function findByUser(User $user): array
    {
        return $this->findBy(['user' => $user], ['createdAt' => 'DESC']);
    }

    /**
     * Find imports within a date range
     *
     * @return ImportManual[]
     */
    public function findByDateRange(\DateTimeInterface $startDate, \DateTimeInterface $endDate): array
    {
        return $this->createQueryBuilder('im')
            ->andWhere('im.createdAt >= :startDate')
            ->andWhere('im.createdAt <= :endDate')
            ->setParameter('startDate', $startDate)
            ->setParameter('endDate', $endDate)
            ->orderBy('im.createdAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find recent imports
     *
     * @return ImportManual[]
     */
    public function findRecent(int $limit = 20): array
    {
        return $this->findBy([], ['createdAt' => 'DESC'], $limit);
    }

    /**
     * Save an import manual to the database
     */
    public function save(ImportManual $importManual, bool $flush = true): void
    {
        $this->getEntityManager()->persist($importManual);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove an import manual from the database
     */
    public function remove(ImportManual $importManual, bool $flush = true): void
    {
        $this->getEntityManager()->remove($importManual);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
