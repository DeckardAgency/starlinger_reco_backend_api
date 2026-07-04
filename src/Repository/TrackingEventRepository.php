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
     * Latest event status per order, in one query (window function) instead of
     * one findLatestForOrder query per order. Orders with no events are absent
     * from the result.
     *
     * @param int[] $orderIds
     * @return array<int, string> orderId => latest event status
     */
    public function findLatestStatusByOrderIds(array $orderIds): array
    {
        if ($orderIds === []) {
            return [];
        }

        $rows = $this->getEntityManager()->getConnection()->fetchAllAssociative(
            'SELECT order_ref_id, status FROM (
                SELECT order_ref_id, status,
                       ROW_NUMBER() OVER (PARTITION BY order_ref_id ORDER BY occurred_at DESC, id DESC) AS rn
                FROM tracking_event
                WHERE order_ref_id IN (:ids)
            ) latest WHERE rn = 1',
            ['ids' => $orderIds],
            ['ids' => \Doctrine\DBAL\ArrayParameterType::INTEGER]
        );

        $statuses = [];
        foreach ($rows as $row) {
            $statuses[(int) $row['order_ref_id']] = (string) $row['status'];
        }

        return $statuses;
    }

    /**
     * All (status, occurredAt) pairs already recorded for the order, as a set of
     * "status|Y-m-d H:i:s" keys for in-memory dedupe (one query instead of one
     * existsForOrder query per carrier event).
     *
     * @return array<string, true>
     */
    public function findExistingEventKeys(Order $order): array
    {
        $rows = $this->createQueryBuilder('te')
            ->select('te.status', 'te.occurredAt')
            ->where('te.orderRef = :order')
            ->setParameter('order', $order)
            ->getQuery()
            ->getArrayResult();

        $keys = [];
        foreach ($rows as $row) {
            $keys[$row['status'] . '|' . $row['occurredAt']->format('Y-m-d H:i:s')] = true;
        }

        return $keys;
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
