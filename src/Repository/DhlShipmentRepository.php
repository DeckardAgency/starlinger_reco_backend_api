<?php

namespace App\Repository;

use App\Entity\DhlShipment;
use App\Entity\Order;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<DhlShipment>
 */
class DhlShipmentRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, DhlShipment::class);
    }

    /**
     * The order's current (not cancelled) shipment, newest first.
     */
    public function findActiveForOrder(Order $order): ?DhlShipment
    {
        return $this->createQueryBuilder('s')
            ->where('s.order = :order')
            ->andWhere('s.status = :status')
            ->setParameter('order', $order)
            ->setParameter('status', DhlShipment::STATUS_BOOKED)
            ->orderBy('s.createdAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }
}
