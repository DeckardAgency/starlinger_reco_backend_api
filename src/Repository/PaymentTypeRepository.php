<?php

namespace App\Repository;

use App\Entity\PaymentType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<PaymentType>
 *
 * @method PaymentType|null find($id, $lockMode = null, $lockVersion = null)
 * @method PaymentType|null findOneBy(array $criteria, array $orderBy = null)
 * @method PaymentType[]    findAll()
 * @method PaymentType[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class PaymentTypeRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, PaymentType::class);
    }

    /**
     * Find payment type by legacy ID (for migration)
     */
    public function findByLegacyId(int $legacyId): ?PaymentType
    {
        return $this->findOneBy(['legacyId' => $legacyId]);
    }

    /**
     * Find payment type by provider code
     */
    public function findByProviderCode(string $providerCode): ?PaymentType
    {
        return $this->findOneBy(['providerCode' => $providerCode]);
    }

    /**
     * Find the default payment type
     */
    public function findDefault(): ?PaymentType
    {
        return $this->findOneBy(['useAsDefault' => true, 'isActive' => true]);
    }

    /**
     * Find all active payment types
     *
     * @return PaymentType[]
     */
    public function findAllActive(): array
    {
        return $this->findBy(['isActive' => true], ['sortOrder' => 'ASC']);
    }

    /**
     * Save a payment type to the database
     */
    public function save(PaymentType $paymentType, bool $flush = true): void
    {
        $this->getEntityManager()->persist($paymentType);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove a payment type from the database
     */
    public function remove(PaymentType $paymentType, bool $flush = true): void
    {
        $this->getEntityManager()->remove($paymentType);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
