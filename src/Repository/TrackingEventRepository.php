<?php

namespace App\Repository;

use App\Entity\Order;
use App\Entity\TrackingEvent;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<TrackingEvent>
 */
class TrackingEventRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, TrackingEvent::class);
    }

    /**
     * @return TrackingEvent[] ordered by occurredAt DESC
     */
    public function findByOrder(Order $order): array
    {
        return $this->createQueryBuilder('te')
            ->where('te.orderRef = :order')
            ->setParameter('order', $order)
            ->orderBy('te.occurredAt', 'DESC')
            ->getQuery()
            ->getResult();
    }

    /**
     * @return TrackingEvent[] orders that are shipped but not yet delivered (or other final states)
     */
    public function findLatestForOrder(Order $order): ?TrackingEvent
    {
        return $this->createQueryBuilder('te')
            ->where('te.orderRef = :order')
            ->setParameter('order', $order)
            ->orderBy('te.occurredAt', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();
    }

    /**
     * Has an event with this status + occurredAt already been recorded for the order?
     */
    public function existsForOrder(Order $order, string $status, \DateTimeInterface $occurredAt): bool
    {
        $count = (int) $this->createQueryBuilder('te')
            ->select('COUNT(te.id)')
            ->where('te.orderRef = :order')
            ->andWhere('te.status = :status')
            ->andWhere('te.occurredAt = :occurredAt')
            ->setParameter('order', $order)
            ->setParameter('status', $status)
            ->setParameter('occurredAt', $occurredAt)
            ->getQuery()
            ->getSingleScalarResult();

        return $count > 0;
    }
}
