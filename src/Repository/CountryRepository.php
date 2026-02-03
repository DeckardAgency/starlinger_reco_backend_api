<?php

namespace App\Repository;

use App\Entity\Country;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Country>
 *
 * @method Country|null find($id, $lockMode = null, $lockVersion = null)
 * @method Country|null findOneBy(array $criteria, array $orderBy = null)
 * @method Country[]    findAll()
 * @method Country[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class CountryRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Country::class);
    }

    /**
     * Find country by ISO code (2-letter)
     */
    public function findByCode(string $code): ?Country
    {
        return $this->findOneBy(['code' => strtoupper($code)]);
    }

    /**
     * Find country by ISO 3166-1 alpha-3 code
     */
    public function findByAlpha3Code(string $code): ?Country
    {
        return $this->findOneBy(['iso31661Alpha3Code' => strtoupper($code)]);
    }

    /**
     * Find country by legacy ID (for migration)
     */
    public function findByLegacyId(int $legacyId): ?Country
    {
        return $this->findOneBy(['legacyId' => $legacyId]);
    }

    /**
     * Find all EU countries
     *
     * @return Country[]
     */
    public function findEUCountries(): array
    {
        return $this->findBy(['europeanUnion' => true, 'isActive' => true], ['name' => 'ASC']);
    }

    /**
     * Find all active countries
     *
     * @return Country[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true], ['name' => 'ASC']);
    }

    /**
     * Save a country to the database
     */
    public function save(Country $country, bool $flush = true): void
    {
        $this->getEntityManager()->persist($country);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove a country from the database
     */
    public function remove(Country $country, bool $flush = true): void
    {
        $this->getEntityManager()->remove($country);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
