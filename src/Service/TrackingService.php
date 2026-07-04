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
use Symfony\Component\Workflow\WorkflowInterface;

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
        private readonly WorkflowInterface $orderStateMachine,
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

        // One query for all existing (status, occurredAt) pairs instead of one per carrier event
        $existingKeys = $this->trackingEventRepository->findExistingEventKeys($order);

        $createdCount = 0;
        foreach ($dtos as $dto) {
            // Dedupe: same status + same occurredAt
            $key = $dto->status->value . '|' . $dto->occurredAt->format('Y-m-d H:i:s');
            if (isset($existingKeys[$key])) {
                continue;
            }
            $existingKeys[$key] = true; // also dedupes within this carrier response

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

        if ($createdCount > 0) {
            $this->entityManager->flush();
        }

        // Even with no new events, re-derive the order status so a previously
        // missed sync (e.g. crash between flush and sync) heals on the next run.
        $newLatest = $this->trackingEventRepository->findLatestForOrder($order);
        if ($newLatest) {
            $this->syncOrderStatusFromTracking($order, $newLatest->getStatus());
        }

        if ($createdCount === 0) {
            return 0;
        }

        // If the status actually changed, fire an async message so handlers can email the customer.
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
     * Advance the order through its workflow based on what the carrier reports.
     *
     * Carrier movement (picked up / in transit / out for delivery) walks the order
     * to "shipped"; a delivered parcel walks it on to "delivered". Each step goes
     * through the state machine, so guards and status-change notifications apply.
     * "waiting_for_payment" has no automatic path out — payment stays a human call.
     * Returns and exceptions are only logged: both need a human decision.
     */
    private function syncOrderStatusFromTracking(Order $order, ?string $latestStatus): void
    {
        $trackingStatus = TrackingStatus::tryFrom((string) $latestStatus);
        if ($trackingStatus === null) {
            return;
        }

        if (in_array($trackingStatus, [TrackingStatus::RETURNED, TrackingStatus::EXCEPTION], true)) {
            $this->logger->warning('Carrier reports a problem shipment; order needs manual review', [
                'order_id' => $order->getId(),
                'order_status' => $order->getStatus(),
                'tracking_status' => $trackingStatus->value,
            ]);
            return;
        }

        $transitions = match ($trackingStatus) {
            TrackingStatus::PICKED_UP,
            TrackingStatus::IN_TRANSIT,
            TrackingStatus::OUT_FOR_DELIVERY => ['start_processing', 'ready_to_ship', 'ship'],
            TrackingStatus::DELIVERED => ['start_processing', 'ready_to_ship', 'ship', 'deliver'],
            default => [], // CREATED (label only): parcel not moving yet
        };

        $applied = [];
        foreach ($transitions as $transition) {
            if ($this->orderStateMachine->can($order, $transition)) {
                $this->orderStateMachine->apply($order, $transition);
                $applied[] = $transition;
            }
        }

        if ($applied === []) {
            return;
        }

        $this->entityManager->flush();

        $this->logger->info('Order status advanced from carrier tracking', [
            'order_id' => $order->getId(),
            'tracking_status' => $trackingStatus->value,
            'transitions' => $applied,
            'new_order_status' => $order->getStatus(),
        ]);
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
            ->setParameter('statuses', [
                Order::STATUS_NEW,
                Order::STATUS_IN_PROCESS,
                Order::STATUS_READY_FOR_SHIPMENT,
                Order::STATUS_SHIPPED,
            ]);

        $orders = $qb->getQuery()->getResult();

        // Latest event status for all candidates in one query (was one query per order)
        $latestStatuses = $this->trackingEventRepository->findLatestStatusByOrderIds(
            array_map(static fn (Order $o) => (int) $o->getId(), $orders)
        );

        // Filter out orders whose latest tracking event is final (delivered/returned)
        return array_filter($orders, function (Order $o) use ($latestStatuses) {
            $latestStatus = $latestStatuses[(int) $o->getId()] ?? null;
            if ($latestStatus === null) {
                return true; // never refreshed yet
            }
            $status = TrackingStatus::tryFrom($latestStatus);
            return $status === null || !$status->isFinal();
        });
    }
}
