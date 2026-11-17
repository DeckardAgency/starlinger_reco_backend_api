<?php

namespace App\EventListener;

use App\Entity\Order;
use App\Service\OrderNumberSequence;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsDoctrineListener;
use Doctrine\ORM\Event\OnFlushEventArgs;
use Doctrine\ORM\Events;

/**
 * Gives an order its final, numeric order number the moment it stops being a
 * draft — whichever path submits it (API POST/PATCH, /submit, checkout). Drafts
 * keep the provisional "ORD-…" number from the Order constructor; imported
 * legacy orders already carry their numeric increment_id and are left alone.
 */
#[AsDoctrineListener(event: Events::onFlush)]
final class OrderNumberListener
{
    public function __construct(private readonly OrderNumberSequence $sequence) {}

    public function onFlush(OnFlushEventArgs $args): void
    {
        $em = $args->getObjectManager();
        $uow = $em->getUnitOfWork();
        $metadata = $em->getClassMetadata(Order::class);

        foreach ([$uow->getScheduledEntityInsertions(), $uow->getScheduledEntityUpdates()] as $entities) {
            foreach ($entities as $entity) {
                if ($entity instanceof Order && self::needsNumber($entity)) {
                    $entity->setOrderNumber($this->sequence->next());
                    $uow->recomputeSingleEntityChangeSet($metadata, $entity);
                }
            }
        }
    }

    private static function needsNumber(Order $order): bool
    {
        return !$order->isDraft() && !ctype_digit((string) $order->getOrderNumber());
    }
}
