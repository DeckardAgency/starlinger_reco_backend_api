<?php

namespace App\Repository;

use App\Entity\Contact;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<Contact>
 *
 * @method Contact|null find($id, $lockMode = null, $lockVersion = null)
 * @method Contact|null findOneBy(array $criteria, array $orderBy = null)
 * @method Contact[]    findAll()
 * @method Contact[]    findBy(array $criteria, array $orderBy = null, $limit = null, $offset = null)
 */
class ContactRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, Contact::class);
    }

    /**
     * Find contacts by account ID
     *
     * @return Contact[]
     */
    public function findByAccountId(int $accountId): array
    {
        return $this->findBy(['accountId' => $accountId]);
    }

    /**
     * Find active contacts
     *
     * @return Contact[]
     */
    public function findActive(): array
    {
        return $this->findBy(['isActive' => 1]);
    }

    /**
     * Search contacts by name or email
     *
     * @return Contact[]
     */
    public function search(string $query): array
    {
        return $this->createQueryBuilder('c')
            ->where('c.firstName LIKE :query')
            ->orWhere('c.lastName LIKE :query')
            ->orWhere('c.email LIKE :query')
            ->orWhere('c.fullName LIKE :query')
            ->setParameter('query', '%' . $query . '%')
            ->orderBy('c.lastName', 'ASC')
            ->addOrderBy('c.firstName', 'ASC')
            ->getQuery()
            ->getResult();
    }

    /**
     * Save a contact to the database
     */
    public function save(Contact $contact, bool $flush = true): void
    {
        $this->getEntityManager()->persist($contact);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }

    /**
     * Remove a contact from the database
     */
    public function remove(Contact $contact, bool $flush = true): void
    {
        $this->getEntityManager()->remove($contact);

        if ($flush) {
            $this->getEntityManager()->flush();
        }
    }
}
