<?php

namespace App\Repository;

use App\Entity\Address;
use App\Entity\Client;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Address>
 *
 * @method Address|null find($id, $lockMode = null, $lockVersion = null)
 * @method Address|null findOneBy(array $criteria, array $orderBy = null)
 * @method Address[]    findAll()
 * @method Address[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class AddressRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Address::class);
    }

    /**
     * Find all addresses for a client
     *
     * @return Address[]
     */
    public function findByClient(Client $client): array
    {
        return $this->findBy(['client' => $client, 'isActive' => true], ['createdAt' => 'DESC']);
    }

    /**
     * Find billing addresses for a client
     *
     * @return Address[]
     */
    public function findBillingAddresses(Client $client): array
    {
        return $this->findBy([
            'client' => $client,
            'isBilling' => true,
            'isActive' => true
        ], ['createdAt' => 'DESC']);
    }

    /**
     * Find delivery addresses for a client
     *
     * @return Address[]
     */
    public function findDeliveryAddresses(Client $client): array
    {
        return $this->findBy([
            'client' => $client,
            'isDelivery' => true,
            'isActive' => true
        ], ['createdAt' => 'DESC']);
    }

    /**
     * Find address by legacy ID (for migration from address_entity table)
     */
    public function findByLegacyId(int $legacyId): ?Address
    {
        return $this->findOneBy(['legacyId' => $legacyId]);
    }

    /**
     * Save an address to the database
     */
    public function save(Address $address, bool $flush = true): void
    {
        $this->getEntityManager()->persist($address);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove an address from the database
     */
    public function remove(Address $address, bool $flush = true): void
    {
        $this->getEntityManager()->remove($address);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
