<?php

namespace App\Repository;

use App\Entity\Warehouse;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Warehouse>
 *
 * @method Warehouse|null find($id, $lockMode = null, $lockVersion = null)
 * @method Warehouse|null findOneBy(array $criteria, array $orderBy = null)
 * @method Warehouse[]    findAll()
 * @method Warehouse[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class WarehouseRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Warehouse::class);
    }

    /**
     * Find warehouse by legacy ID (for migration)
     */
    public function findByLegacyId(int $legacyId): ?Warehouse
    {
        return $this->findOneBy(['legacyId' => $legacyId]);
    }

    /**
     * Find warehouse by code
     */
    public function findByCode(string $code): ?Warehouse
    {
        return $this->findOneBy(['code' => $code]);
    }

    /**
     * Find all active warehouses
     *
     * @return Warehouse[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true], ['name' => 'ASC']);
    }

    /**
     * Find warehouses that can be shown as locations (e.g., for store locator)
     *
     * @return Warehouse[]
     */
    public function findLocations(): array
    {
        return $this->findBy(['showAsLocation' => true, 'isActive' => true], ['name' => 'ASC']);
    }

    /**
     * Save a warehouse to the database
     */
    public function save(Warehouse $warehouse, bool $flush = true): void
    {
        $this->getEntityManager()->persist($warehouse);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove a warehouse from the database
     */
    public function remove(Warehouse $warehouse, bool $flush = true): void
    {
        $this->getEntityManager()->remove($warehouse);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
