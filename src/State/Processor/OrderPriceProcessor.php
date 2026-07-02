<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Message\OrderCreatedMessage;
use App\Message\OrderStatusChangedMessage;
use App\Security\ClientAgentAuthorization;
use App\Service\DiscountResolver;
use App\Service\PriceCalculator;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\WorkflowInterface;
use Symfony\Component\Workflow\Exception\TransitionException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Custom processor that ensures client-specific prices are applied to order items
 * and handles order creation workflow with Symfony Workflow component
 */
final class OrderPriceProcessor implements ProcessorInterface
{
    public function __construct(
        private ProcessorInterface $persistProcessor,
        private EntityManagerInterface $entityManager,
        private PriceCalculator $priceCalculator,
        private DiscountResolver $discountResolver,
        private LoggerInterface $logger,
        private Security $security,
        private MessageBusInterface $messageBus,
        private WorkflowInterface $orderStateMachine,
        private ClientAgentAuthorization $agentAuth
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        // Only process Order entities
        if (!$data instanceof Order) {
            return $this->persistProcessor->process($data, $operation, $uriVariables, $context);
        }

        // Get the authenticated user who is making this request
        $authenticatedUser = $this->security->getUser();
        $modifiedBy = null;

        if ($authenticatedUser instanceof User) {
            $modifiedBy = [
                'id' => $authenticatedUser->getId(),
                'email' => $authenticatedUser->getEmail(),
                'firstName' => $authenticatedUser->getFirstName(),
                'lastName' => $authenticatedUser->getLastName(),
                'fullName' => $authenticatedUser->getFullName()
            ];
        }

        // Check if this is a draft submission operation
        $isDraftSubmission = str_contains($operation->getUriTemplate() ?? '', '/submit');

        // Capture the original status before processing
        $originalOrder = null;
        $isNewOrder = false;

        if ($data->getId()) {
            $uow = $this->entityManager->getUnitOfWork();
            $originalOrder = $uow->getOriginalEntityData($data);

            // If no original data, try to fetch from database
            if (!$originalOrder) {
                $existingOrder = $this->entityManager->getRepository(Order::class)->find($data->getId());
                if ($existingOrder) {
                    $originalOrder = [
                        'status' => $existingOrder->getStatus(),
                        'isDraft' => $existingOrder->isDraft()
                    ];
                }
            }
        } else {
            $isNewOrder = true;
        }

        $this->logger->info('Processing order for price calculation', [
            'order_id' => $data->getId(),
            'order_number' => $data->getOrderNumber(),
            'user' => $data->getUser()?->getEmail(),
            'items_count' => $data->getItems()->count(),
            'operation' => $operation->getName(),
            'is_new_order' => $isNewOrder,
            'current_status' => $data->getStatus(),
            'original_status' => $originalOrder['status'] ?? null,
            'modified_by' => $modifiedBy
        ]);

        // Bind the order to the authenticated user. Non-admins may never create an
        // order on behalf of another user (which would attribute it to another tenant);
        // only admins may set an explicit user, defaulting to themselves.
        if ($operation->getMethod() === 'POST') {
            $authUser = $this->security->getUser();
            if (!$this->security->isGranted('ROLE_ADMIN')) {
                if ($authUser instanceof User) {
                    $data->setUser($authUser);
                }
            } elseif ($data->getUser() === null && $authUser instanceof User) {
                $data->setUser($authUser);
            }
        }

        // Client-agent delegation: validate any onBehalfOfClient (order-level AND
        // per-item) for authorised agents, otherwise strip every reference so a
        // non-agent can never persist a delegation.
        if ($authenticatedUser instanceof User) {
            if ($this->agentAuth->mayActOnBehalf($authenticatedUser)) {
                $this->agentAuth->assertCanActFor($authenticatedUser, $data->getOnBehalfOfClient());
                $this->agentAuth->assertCanActForItems($authenticatedUser, $data->getItems());
            } else {
                $this->agentAuth->stripOnBehalfOf($data, $data->getItems());
            }
        }

        // Reject non-draft orders with no items
        if (!$data->isDraft() && $data->getItems()->isEmpty()) {
            throw new BadRequestHttpException('Cannot create an order with no items.');
        }

        // Ensure all order items have the correct client-specific prices
        $this->updateOrderItemPrices($data);

        // Recalculate order total
        $data->calculateTotalAmount();

        $this->logger->info('Order prices calculated', [
            'order_id' => $data->getId(),
            'total_amount' => $data->getTotalAmount()
        ]);

        // Handle workflow transitions for status changes
        if ($originalOrder && !$isNewOrder) {
            $oldStatus = $originalOrder['status'] ?? null;
            $newStatus = $data->getStatus();

            // Validate and set fields for shipped status
            if ($newStatus === Order::STATUS_SHIPPED && $oldStatus !== Order::STATUS_SHIPPED) {
                $this->validateAndSetDispatchedFields($data, $authenticatedUser);
            }

            // Validate and set fields for canceled status
            if ($newStatus === Order::STATUS_CANCELED && $oldStatus !== Order::STATUS_CANCELED) {
                $this->validateAndSetCanceledFields($data, $authenticatedUser);
            }

            $this->handleWorkflowTransition($data, $originalOrder);
        }

        // Process with the standard persist processor
        $result = $this->persistProcessor->process($data, $operation, $uriVariables, $context);

        // Handle message dispatching after persistence
        $this->handleMessageDispatching($data, $operation, $isNewOrder, $originalOrder, $isDraftSubmission, $modifiedBy);

        return $result;
    }

    /**
     * Handle message dispatching based on the operation and order state
     */
    private function handleMessageDispatching(
        Order $order,
        Operation $operation,
        bool $isNewOrder,
        ?array $originalOrder,
        bool $isDraftSubmission,
        ?array $modifiedBy
    ): void
    {
        // Skip all message dispatching for draft orders
        if ($order->isDraft()) {
            $this->logger->info('Skipping message dispatch for draft order', [
                'order_id' => $order->getId(),
                'status' => $order->getStatus()
            ]);
            return;
        }

        // For draft submission, always dispatch OrderCreatedMessage
        if ($isDraftSubmission && !$order->isDraft()) {
            try {
                $this->logger->info('Dispatching OrderCreatedMessage for draft submission', [
                    'order_id' => $order->getId(),
                    'status' => $order->getStatus(),
                    'modified_by' => $modifiedBy
                ]);

                $message = new OrderCreatedMessage($order->getId());
                $message->addMetadata('modifiedBy', $modifiedBy);
                $message->addMetadata('operation', 'draft_submission');

                $this->messageBus->dispatch($message);
                return; // Exit early to prevent duplicate messages
            } catch (\Exception $e) {
                $this->logger->error('Failed to dispatch OrderCreatedMessage for draft submission', [
                    'error' => $e->getMessage()
                ]);
            }
            return;
        }

        // For new orders that are not drafts, dispatch OrderCreatedMessage
        if ($isNewOrder && $operation->getMethod() === 'POST') {
            try {
                $this->logger->info('Dispatching OrderCreatedMessage for new order', [
                    'order_id' => $order->getId(),
                    'status' => $order->getStatus(),
                    'modified_by' => $modifiedBy
                ]);

                $message = new OrderCreatedMessage($order->getId());
                $message->addMetadata('modifiedBy', $modifiedBy);
                $message->addMetadata('operation', 'create');

                $this->messageBus->dispatch($message);
            } catch (\Exception $e) {
                $this->logger->error('Failed to dispatch OrderCreatedMessage', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        // NOTE: For existing orders, status change messages are now handled by OrderWorkflowSubscriber
        // The workflow.order.completed event triggers OrderStatusChangedMessage dispatch
        // No additional dispatching needed here to avoid duplicate emails
    }

    /**
     * Update all order item prices based on client-specific pricing
     */
    private function updateOrderItemPrices(Order $order): void
    {
        $user = $order->getUser();
        $client = $user?->getClient();

        // For agent on-behalf-of orders, price/tax against the managed client.
        // Order-level applies to the whole order (and tax/shipping); each item may
        // additionally override with its own onBehalfOfClient (mixed-client carts).
        $orderClient = $order->getOnBehalfOfClient() ?? $client;

        foreach ($order->getItems() as $item) {
            /** @var OrderItem $item */
            $product = $item->getProduct();

            // The client whose pricing applies to THIS line.
            $itemClient = $item->getOnBehalfOfClient() ?? $orderClient;

            if (!$product) {
                $this->logger->warning('Order item without product', [
                    'item_id' => $item->getId()
                ]);
                continue;
            }

            // Ensure the item has the order reference set
            if ($item->getOrderRef() === null) {
                $item->setOrderRef($order);
            }

            // Round quantity up to match product's qtyStep when set
            $qtyStep = $product->getQtyStep();
            if ($qtyStep !== null && $qtyStep > 1) {
                $currentQty = $item->getQuantity();
                $remainder = $currentQty % $qtyStep;
                if ($remainder !== 0) {
                    $adjustedQty = $currentQty + ($qtyStep - $remainder);
                    $item->setQuantity($adjustedQty);
                    $this->logger->info('Rounded order item quantity up to match qtyStep', [
                        'product' => $product->getPartNo(),
                        'original' => $currentQty,
                        'adjusted' => $adjustedQty,
                        'step' => $qtyStep,
                    ]);
                }
            }

            // Get the appropriate price
            $price = $product->getPrice(); // Default price
            $isCustomPrice = false;

            if ($itemClient) {
                // Use PriceCalculator service to get client price
                $clientProductPrice = $this->priceCalculator->getClientProductPrice($itemClient, $product);

                if ($clientProductPrice && $clientProductPrice->isValid()) {
                    $price = $clientProductPrice->getEffectivePrice();
                    $isCustomPrice = true;

                    $this->logger->debug('Applying client-specific price', [
                        'client' => $itemClient->getCode(),
                        'product' => $product->getName(),
                        'standard_price' => $product->getPrice(),
                        'client_price' => $price,
                        'discount_percentage' => $clientProductPrice->getDiscountPercentage()
                    ]);
                }
            }

            // Apply campaign discount on top of resolved price
            $resolved = $this->discountResolver->resolveDiscount($product, $itemClient);
            if ($resolved !== null) {
                if ($resolved->fixedPrice !== null) {
                    $price = min($price, $resolved->fixedPrice);
                } elseif ($resolved->percent > 0) {
                    $price = round($price * (1 - $resolved->percent / 100), 2);
                }
            }

            // Store the original catalog price for discount tracking
            $originalPrice = $product->getPrice();
            $item->setOriginalUnitPrice($originalPrice);

            // Update the item with the correct (possibly discounted) price
            $item->setUnitPrice($price);
            $item->setIsCustomPrice($isCustomPrice);

            // Calculate discount percentage
            if ($originalPrice > 0 && $price < $originalPrice) {
                $discountPercent = (($originalPrice - $price) / $originalPrice) * 100;
                $item->setDiscountPercent(round($discountPercent, 2));
            } else {
                $item->setDiscountPercent(0);
            }

            // Recalculate subtotal (without tax yet - tax applied below)
            $item->setQuantity($item->getQuantity()); // This triggers subtotal recalculation
        }

        // Determine fallback tax rate from shipping address country.
        // Prefer the explicitly selected shipping address (order.shippingAddressId);
        // fall back to the client's first active delivery address for legacy/empty cases.
        $countryTaxPercent = 0;
        $resolvedAddress = null;

        if ($order->getShippingAddressId() !== null) {
            $candidate = $this->entityManager->find(\App\Entity\Address::class, $order->getShippingAddressId());
            // Only accept the address if it belongs to the order's (on-behalf) client (security)
            if ($candidate && $orderClient && $candidate->getClient()?->getId() === $orderClient->getId()) {
                $resolvedAddress = $candidate;
                // Keep the order's shippingAddress text in sync with the resolved address
                $order->setShippingAddress($candidate->getFullAddress());
            } else {
                $this->logger->warning('Shipping address does not belong to client; ignoring', [
                    'order_id' => $order->getId(),
                    'shipping_address_id' => $order->getShippingAddressId(),
                    'client_id' => $orderClient?->getId(),
                ]);
                $order->setShippingAddressId(null);
            }
        }

        if ($resolvedAddress === null && $orderClient) {
            foreach ($orderClient->getAddresses() as $address) {
                if ($address->getIsDelivery() && $address->getIsActive() && $address->getCountry()) {
                    $resolvedAddress = $address;
                    break;
                }
            }
        }

        if ($resolvedAddress !== null && $resolvedAddress->getCountry()) {
            $countryTax = $resolvedAddress->getCountry()->getTaxType()?->getPercent();
            if ($countryTax !== null) {
                $countryTaxPercent = (float) $countryTax;
            }
        }

        // Apply the same country-derived tax rate to all items
        foreach ($order->getItems() as $item) {
            $item->setTaxPercent($countryTaxPercent);
        }
    }

    /**
     * Handle workflow transitions when status changes
     */
    private function handleWorkflowTransition(Order $order, array $originalOrder): void
    {
        $oldStatus = $originalOrder['status'] ?? null;
        $newStatus = $order->getStatus();

        // If status hasn't changed, nothing to do
        if ($oldStatus === $newStatus) {
            return;
        }

        $this->logger->info('Handling workflow transition for order', [
            'order_id' => $order->getId(),
            'old_status' => $oldStatus,
            'new_status' => $newStatus,
            'current_marking' => $this->orderStateMachine->getMarking($order)->getPlaces()
        ]);

        try {
            // Temporarily reset status to old status so workflow can track the transition
            $order->setStatus($oldStatus);

            // Determine which transition to apply
            $transition = $this->determineOrderTransition($oldStatus, $newStatus);

            $this->logger->info('Attempting workflow transition', [
                'transition' => $transition,
                'can_apply' => $transition ? $this->orderStateMachine->can($order, $transition) : false,
                'enabled_transitions' => array_map(
                    fn($t) => $t->getName(),
                    $this->orderStateMachine->getEnabledTransitions($order)
                )
            ]);

            if ($transition && $this->orderStateMachine->can($order, $transition)) {
                // Apply the workflow transition
                $this->orderStateMachine->apply($order, $transition);

                $this->logger->info('Workflow transition applied successfully', [
                    'order_id' => $order->getId(),
                    'transition' => $transition,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                    'final_status' => $order->getStatus()
                ]);
            } else {
                // No direct workflow transition available. Only admins may force an
                // arbitrary status; non-admins must go through a valid transition.
                if (!$this->security->isGranted('ROLE_ADMIN')) {
                    $order->setStatus($oldStatus);
                    throw new BadRequestHttpException(sprintf(
                        'You are not allowed to change the order status from "%s" to "%s".',
                        (string) $oldStatus,
                        (string) $newStatus
                    ));
                }

                $order->setStatus($newStatus);

                $this->logger->info('Status set directly by admin (no workflow transition)', [
                    'order_id' => $order->getId(),
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus
                ]);
            }
        } catch (TransitionException $e) {
            $this->logger->error('Workflow transition failed', [
                'order_id' => $order->getId(),
                'old_status' => $oldStatus,
                'new_status' => $newStatus,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }

    /**
     * Determine which workflow transition to apply based on status change
     */
    private function determineOrderTransition(string $oldStatus, string $newStatus): ?string
    {
        // Map status changes to workflow transitions
        $transitionMap = [
            Order::STATUS_DRAFT => [
                Order::STATUS_NEW => 'submit',
                Order::STATUS_CANCELED => 'cancel',
            ],
            Order::STATUS_NEW => [
                Order::STATUS_IN_PROCESS => 'start_processing',
                Order::STATUS_WAITING_FOR_PAYMENT => 'await_payment',
                Order::STATUS_CANCELED => 'cancel',
            ],
            Order::STATUS_IN_PROCESS => [
                Order::STATUS_WAITING_FOR_PAYMENT => 'await_payment',
                Order::STATUS_READY_FOR_SHIPMENT => 'ready_to_ship',
                Order::STATUS_CANCELED => 'cancel',
            ],
            Order::STATUS_WAITING_FOR_PAYMENT => [
                Order::STATUS_IN_PROCESS => 'payment_received',
                Order::STATUS_CANCELED => 'cancel',
            ],
            Order::STATUS_READY_FOR_SHIPMENT => [
                Order::STATUS_SHIPPED => 'ship',
                Order::STATUS_CANCELED => 'cancel',
            ],
            Order::STATUS_SHIPPED => [
                Order::STATUS_DELIVERED => 'deliver',
                Order::STATUS_REVERSAL => 'reverse',
            ],
            Order::STATUS_DELIVERED => [
                Order::STATUS_REVERSAL => 'reverse',
            ],
        ];

        return $transitionMap[$oldStatus][$newStatus] ?? null;
    }

    /**
     * Validate and set fields when order is dispatched
     */
    private function validateAndSetDispatchedFields(Order $order, ?User $authenticatedUser): void
    {
        // Validate required tracking fields
        if (empty($order->getTrackingNumber())) {
            throw new BadRequestHttpException('Tracking number is required when dispatching an order.');
        }

        // Auto-fill carrier from delivery type if not explicitly set
        if (empty($order->getTrackingCarrier()) && $order->getDeliveryType()?->getCarrierCode()) {
            $order->setTrackingCarrier($order->getDeliveryType()->getCarrierCode());
        }

        if (empty($order->getTrackingCarrier())) {
            throw new BadRequestHttpException(
                'Tracking carrier is required when dispatching an order. Set it on the delivery type or pass trackingCarrier explicitly.'
            );
        }

        // Validate carrier is one of the allowed values
        $allowedCarriers = ['dhl', 'ups', 'fedex', 'dpd', 'gls', 'other'];
        if (!in_array(strtolower($order->getTrackingCarrier()), $allowedCarriers)) {
            throw new BadRequestHttpException(
                sprintf('Invalid tracking carrier. Allowed values: %s', implode(', ', $allowedCarriers))
            );
        }

        // Auto-generate tracking URL if not provided
        if (empty($order->getTrackingUrl())) {
            $generatedUrl = $order->generateTrackingUrl();
            if ($generatedUrl) {
                $order->setTrackingUrl($generatedUrl);
            }
        }

        // Set dispatched timestamp and user
        $order->setDispatchedAt(new \DateTime());
        if ($authenticatedUser instanceof User) {
            $order->setDispatchedBy($authenticatedUser);
        }

        $this->logger->info('Order dispatched with tracking info', [
            'order_id' => $order->getId(),
            'tracking_number' => $order->getTrackingNumber(),
            'tracking_carrier' => $order->getTrackingCarrier(),
            'tracking_url' => $order->getTrackingUrl(),
            'dispatched_by' => $authenticatedUser?->getEmail()
        ]);
    }

    /**
     * Validate and set fields when order is canceled
     */
    private function validateAndSetCanceledFields(Order $order, ?User $authenticatedUser): void
    {
        // Validate cancellation reason is provided
        if (empty($order->getCancellationReason())) {
            throw new BadRequestHttpException('Cancellation reason is required when canceling an order.');
        }

        // Set canceled timestamp and user
        $order->setCancelledAt(new \DateTime());
        if ($authenticatedUser instanceof User) {
            $order->setCancelledBy($authenticatedUser);
        }

        $this->logger->info('Order canceled', [
            'order_id' => $order->getId(),
            'cancellation_reason' => $order->getCancellationReason(),
            'cancelled_by' => $authenticatedUser?->getEmail()
        ]);
    }
}
