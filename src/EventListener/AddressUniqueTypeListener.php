<?php

namespace App\EventListener;

use App\Entity\Address;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PostUpdateEventArgs;
use Doctrine\ORM\Events;

/**
 * Ensures only one active billing address per client.
 * When an address is saved with isBilling=true, any other address for the same client
 * with isBilling=true is automatically unset. Multiple delivery addresses are allowed
 * (the customer chooses one at checkout).
 */
#[AsEntityListener(event: Events::postPersist, entity: Address::class)]
#[AsEntityListener(event: Events::postUpdate, entity: Address::class)]
class AddressUniqueTypeListener
{
    public function __construct(
        private EntityManagerInterface $em
    ) {}

    public function postPersist(Address $address, PostPersistEventArgs $event): void
    {
        $this->ensureUniqueTypes($address);
    }

    public function postUpdate(Address $address, PostUpdateEventArgs $event): void
    {
        $this->ensureUniqueTypes($address);
    }

    private function ensureUniqueTypes(Address $address): void
    {
        $client = $address->getClient();
        if (!$client) {
            return;
        }

        if ($address->getIsBilling()) {
            $this->em->createQueryBuilder()
                ->update(Address::class, 'a')
                ->set('a.isBilling', ':false')
                ->where('a.client = :client')
                ->andWhere('a.id != :id')
                ->andWhere('a.isBilling = :true')
                ->setParameter('false', false)
                ->setParameter('client', $client)
                ->setParameter('id', $address->getId())
                ->setParameter('true', true)
                ->getQuery()
                ->execute();
        }

    }
}
