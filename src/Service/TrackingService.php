<?php

namespace App\Service;

use App\Entity\Order;
use App\Entity\TrackingEvent;
use App\Enum\TrackingStatus;
use App\Message\OrderTrackingChangedMessage;
use App\Repository\OrderRepository;
use App\Repository\TrackingEventRepository;
use App\Service\Carrier\DhlClient;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Coordinates tracking refresh: queries the carrier client, dedupes events,
 * persists new ones, and dispatches messages for status transitions.
 */
class TrackingService
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TrackingEventRepository $trackingEventRepository,
        private readonly OrderRepository $orderRepository,
        private readonly DhlClient $dhlClient,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * Fetch the latest tracking events for one order from its carrier and persist new ones.
     *
     * @return int number of new TrackingEvent rows created
     */
    public function refreshOrder(Order $order): int
    {
        if (empty($order->getTrackingNumber())) {
            return 0;
        }

        $carrier = strtolower((string) $order->getTrackingCarrier());
        if ($carrier !== 'dhl') {
            // Other carriers can be plugged in via a CarrierClient interface later.
            return 0;
        }

        $previousLatest = $this->trackingEventRepository->findLatestForOrder($order);
        $previousStatus = $previousLatest?->getStatus();

        $dtos = $this->dhlClient->fetchTrackingEvents($order->getTrackingNumber());
        if (empty($dtos)) {
            return 0;
        }

        $createdCount = 0;
        foreach ($dtos as $dto) {
            // Dedupe: same status + same occurredAt
            if ($this->trackingEventRepository->existsForOrder($order, $dto->status->value, $dto->occurredAt)) {
                continue;
            }

            $event = new TrackingEvent();
            $event->setOrderRef($order);
            $event->setStatus($dto->status->value);
            $event->setOccurredAt($dto->occurredAt instanceof \DateTime
                ? $dto->occurredAt
                : new \DateTime('@' . $dto->occurredAt->getTimestamp()));
            $event->setDescription($dto->description);
            $event->setLocation($dto->location);
            $event->setSource(TrackingEvent::SOURCE_DHL_API);

            $this->entityManager->persist($event);
            $createdCount++;
        }

        if ($createdCount === 0) {
            return 0;
        }

        $this->entityManager->flush();

        // If the status actually changed, fire an async message so handlers can email the customer.
        $newLatest = $this->trackingEventRepository->findLatestForOrder($order);
        if ($newLatest && $newLatest->getStatus() !== $previousStatus) {
            $this->messageBus->dispatch(new OrderTrackingChangedMessage(
                orderId: (int) $order->getId(),
                eventId: (int) $newLatest->getId()
            ));
        }

        $this->logger->info('Tracking refreshed for order', [
            'order_id' => $order->getId(),
            'tracking_number' => $order->getTrackingNumber(),
            'new_events' => $createdCount,
        ]);

        return $createdCount;
    }

    /**
     * Refresh all orders that have a tracking number and aren't yet in a final state.
     *
     * @return array{processed:int, created:int, errors:int}
     */
    public function refreshAllPending(): array
    {
        $processed = 0;
        $created = 0;
        $errors = 0;

        $orders = $this->findPendingOrders();
        foreach ($orders as $order) {
            $processed++;
            try {
                $created += $this->refreshOrder($order);
            } catch (\Throwable $e) {
                $errors++;
                $this->logger->error('Tracking refresh failed', [
                    'order_id' => $order->getId(),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return ['processed' => $processed, 'created' => $created, 'errors' => $errors];
    }

    /**
     * @return Order[] orders with a tracking number whose latest event isn't final
     */
    private function findPendingOrders(): array
    {
        $qb = $this->orderRepository->createQueryBuilder('o');
        $qb->where('o.trackingNumber IS NOT NULL')
            ->andWhere('o.trackingNumber != :empty')
            ->andWhere('o.status IN (:statuses)')
            ->setParameter('empty', '')
            ->setParameter('statuses', [Order::STATUS_SHIPPED, Order::STATUS_IN_PROCESS]);

        $orders = $qb->getQuery()->getResult();

        // Filter out orders whose latest tracking event is final (delivered/returned)
        return array_filter($orders, function (Order $o) {
            $latest = $this->trackingEventRepository->findLatestForOrder($o);
            if (!$latest) {
                return true; // never refreshed yet
            }
            $status = TrackingStatus::tryFrom((string) $latest->getStatus());
            return $status === null || !$status->isFinal();
        });
    }
}
