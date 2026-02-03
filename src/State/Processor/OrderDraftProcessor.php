<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Order;
use App\Repository\OrderRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Processor for managing draft orders (saving and submitting)
 */
class OrderDraftProcessor implements ProcessorInterface
{
    private OrderRepository $orderRepository;
    private Security $security;
    private EntityManagerInterface $entityManager;
    private ProcessorInterface $orderPriceProcessor;
    private LoggerInterface $logger;

    public function __construct(
        EntityManagerInterface $entityManager,
        Security               $security,
        ProcessorInterface     $orderPriceProcessor = null,
        LoggerInterface        $logger = null
    )
    {
        $this->orderRepository = $entityManager->getRepository(Order::class);
        $this->security = $security;
        $this->entityManager = $entityManager;
        $this->orderPriceProcessor = $orderPriceProcessor;
        $this->logger = $logger;
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Order
    {
        $user = $this->security->getUser();

        if (!$user) {
            throw new AccessDeniedHttpException('You must be logged in to manage draft orders');
        }

        // Extract order ID from URI variables
        $orderId = $uriVariables['id'] ?? null;

        if (!$orderId) {
            throw new NotFoundHttpException('Order ID is required');
        }

        // Find the order
        $order = $this->orderRepository->find($orderId);

        if (!$order) {
            throw new NotFoundHttpException('Order not found');
        }

        // Check if the order belongs to the current user
        if ($order->getUser()->getId() !== $user->getId()) {
            throw new AccessDeniedHttpException('You do not have permission to modify this order');
        }

        // Determine the operation (save-draft or submit)
        $path = $operation->getUriTemplate();

        if (str_contains($path, '/save-draft')) {
            // Save as draft
            $order->saveDraft();
            $this->entityManager->flush();

            if ($this->logger) {
                $this->logger->info('Order saved as draft', [
                    'order_id' => $order->getId()->toRfc4122(),
                    'order_number' => $order->getOrderNumber()
                ]);
            }
        } elseif (str_contains($path, '/submit')) {
            // Submit the draft order
            $order->submitOrder();

            // If we have the OrderPriceProcessor, use it to handle the submission
            // This ensures prices are calculated and messages are dispatched properly
            if ($this->orderPriceProcessor) {
                return $this->orderPriceProcessor->process($order, $operation, $uriVariables, $context);
            } else {
                // Fallback: just persist
                $this->entityManager->flush();
            }

            if ($this->logger) {
                $this->logger->info('Draft order submitted', [
                    'order_id' => $order->getId()->toRfc4122(),
                    'order_number' => $order->getOrderNumber(),
                    'new_status' => $order->getStatus()
                ]);
            }
        }

        return $order;
    }
}
