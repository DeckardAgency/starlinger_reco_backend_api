<?php

namespace App\Repository;

use App\Entity\ProductGroup;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<ProductGroup>
 *
 * @method ProductGroup|null find($id, $lockMode = null, $lockVersion = null)
 * @method ProductGroup|null findOneBy(array $criteria, array $orderBy = null)
 * @method ProductGroup[]    findAll()
 * @method ProductGroup[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ProductGroupRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, ProductGroup::class);
    }

    /**
     * Find product group by legacy ID (for migration)
     */
    public function findByLegacyId(int $legacyId): ?ProductGroup
    {
        return $this->findOneBy(['legacyId' => $legacyId]);
    }

    /**
     * Find product group by slug
     */
    public function findBySlug(string $slug): ?ProductGroup
    {
        return $this->findOneBy(['slug' => $slug]);
    }

    /**
     * Find product group by code
     */
    public function findByCode(string $code): ?ProductGroup
    {
        return $this->findOneBy(['productGroupCode' => $code]);
    }

    /**
     * Find all root (top-level) product groups
     *
     * @return ProductGroup[]
     */
    public function findRootGroups(): array
    {
        return $this->createQueryBuilder('pg')
            ->where('pg.parent IS NULL')
            ->andWhere('pg.isActive = :active')
            ->setParameter('active', true)
            ->orderBy('pg.sortOrder', 'ASC')
            ->addOrderBy('pg.name', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Find all active product groups
     *
     * @return ProductGroup[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true], ['sortOrder' => 'ASC', 'name' => 'ASC']);
    }

    /**
     * Find groups shown on homepage
     *
     * @return ProductGroup[]
     */
    public function findHomepageGroups(): array
    {
        return $this->findBy(
            ['showOnHomepage' => true, 'isActive' => true],
            ['sortOrder' => 'ASC', 'name' => 'ASC']
        );
    }

    /**
     * Find children of a product group
     *
     * @return ProductGroup[]
     */
    public function findChildren(ProductGroup $parent): array
    {
        return $this->findBy(
            ['parent' => $parent, 'isActive' => true],
            ['sortOrder' => 'ASC', 'name' => 'ASC']
        );
    }

    /**
     * Save a product group to the database
     */
    public function save(ProductGroup $productGroup, bool $flush = true): void
    {
        $this->getEntityManager()->persist($productGroup);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove a product group from the database
     */
    public function remove(ProductGroup $productGroup, bool $flush = true): void
    {
        $this->getEntityManager()->remove($productGroup);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
