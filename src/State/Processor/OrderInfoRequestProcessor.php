<?php

namespace App\State\Processor;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Order;
use App\Entity\OrderInfoMessage;
use App\Entity\OrderInfoRequest;
use App\Entity\OrderItem;
use App\Entity\User;
use App\Message\OrderInfoRequestMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\AsDecorator;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Workflow\WorkflowInterface;

#[AsDecorator('api_platform.doctrine.orm.state.persist_processor', priority: 15)]
class OrderInfoRequestProcessor implements ProcessorInterface
{
    public function __construct(
        private ProcessorInterface $decorated,
        private MessageBusInterface $messageBus,
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private WorkflowInterface $orderStateMachine,
        private Security $security
    ) {
    }

    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): mixed
    {
        if ($data instanceof OrderInfoRequest && $operation->getMethod() === 'POST') {
            $this->handleInfoRequestCreation($data);
        }

        if ($data instanceof OrderInfoRequest &&
            ($operation->getMethod() === 'PUT' || $operation->getMethod() === 'PATCH')) {
            $this->handleInfoRequestUpdate($data);
        }

        if ($data instanceof OrderInfoMessage && $operation->getMethod() === 'POST') {
            $this->handleMessageCreation($data);
        }

        return $this->decorated->process($data, $operation, $uriVariables, $context);
    }

    private function handleInfoRequestCreation(OrderInfoRequest $infoRequest): void
    {
        $authenticatedUser = $this->security->getUser();

        if (!$authenticatedUser instanceof User) {
            throw new BadRequestHttpException('You must be authenticated to create an info request.');
        }

        $infoRequest->setCreatedBy($authenticatedUser);

        $orderItem = $infoRequest->getOrderItem();
        if (!$orderItem) {
            throw new BadRequestHttpException('Order item is required.');
        }

        $order = $orderItem->getOrderRef();
        if ($order) {
            $infoRequest->setOrder($order);
        } else {
            throw new BadRequestHttpException('Could not determine order for this item.');
        }

        // Handle nested messages
        foreach ($infoRequest->getMessages() as $message) {
            $message->setSender($authenticatedUser);
            $message->setInfoRequest($infoRequest);

            if (!$message->getSenderType()) {
                $message->setSenderType(OrderInfoMessage::SENDER_TYPE_ADMIN);
            }
        }

        // Update item info status to pending
        $orderItem->setInfoStatus(OrderItem::INFO_STATUS_PENDING_INFO);

        // Info requests work at item level; no order status transition needed

        $this->logger->info('Info request created', [
            'info_request_id' => $infoRequest->getId(),
            'order_id' => $order->getId(),
            'item_id' => $orderItem->getId(),
            'created_by' => $authenticatedUser->getEmail(),
            'message_count' => $infoRequest->getMessages()->count()
        ]);

        // Dispatch notification
        // Note: info_request_id will be null here (pre-persist), dispatch after persist if needed
    }

    private function handleInfoRequestUpdate(OrderInfoRequest $infoRequest): void
    {
        $originalData = $this->entityManager->getUnitOfWork()->getOriginalEntityData($infoRequest);

        if (!$originalData) {
            return;
        }

        $oldStatus = $originalData['status'] ?? null;
        $newStatus = $infoRequest->getStatus();

        if ($oldStatus !== $newStatus) {
            $this->handleStatusChange($infoRequest, $oldStatus, $newStatus);
        }
    }

    private function handleStatusChange(OrderInfoRequest $infoRequest, string $oldStatus, string $newStatus): void
    {
        $orderItem = $infoRequest->getOrderItem();
        $order = $infoRequest->getOrder();

        $this->logger->info('Info request status changed', [
            'info_request_id' => $infoRequest->getId(),
            'old_status' => $oldStatus,
            'new_status' => $newStatus
        ]);

        // Update item info status based on request status
        if ($orderItem) {
            switch ($newStatus) {
                case OrderInfoRequest::STATUS_ACCEPTED:
                    $orderItem->setInfoStatus(OrderItem::INFO_STATUS_CLEAR);
                    break;
                case OrderInfoRequest::STATUS_NEEDS_REVISION:
                    $orderItem->setInfoStatus(OrderItem::INFO_STATUS_PENDING_INFO);
                    break;
                case OrderInfoRequest::STATUS_RESPONDED:
                    $orderItem->setInfoStatus(OrderItem::INFO_STATUS_INFO_PROVIDED);
                    break;
            }
        }

        // Check if all info requests are accepted
        if ($order && $newStatus === OrderInfoRequest::STATUS_ACCEPTED) {
            $this->checkAndTransitionOrder($order);
        }

        // Dispatch notification
        if ($infoRequest->getId()) {
            $this->messageBus->dispatch(new OrderInfoRequestMessage(
                $infoRequest->getId(),
                'status_changed',
                $newStatus
            ));
        }
    }

    private function handleMessageCreation(OrderInfoMessage $message): void
    {
        $authenticatedUser = $this->security->getUser();

        if (!$authenticatedUser instanceof User) {
            throw new BadRequestHttpException('You must be authenticated to send a message.');
        }

        $message->setSender($authenticatedUser);

        // Determine sender type based on user roles
        $roles = $authenticatedUser->getRoles();
        if (in_array('ROLE_ADMIN', $roles)) {
            $message->setSenderType(OrderInfoMessage::SENDER_TYPE_ADMIN);
        } else {
            $message->setSenderType(OrderInfoMessage::SENDER_TYPE_CLIENT);
        }

        $infoRequest = $message->getInfoRequest();

        // Client responding
        if ($message->getSenderType() === OrderInfoMessage::SENDER_TYPE_CLIENT && $infoRequest) {
            $infoRequest->markAsResponded();

            $orderItem = $infoRequest->getOrderItem();
            if ($orderItem) {
                $orderItem->setInfoStatus(OrderItem::INFO_STATUS_INFO_PROVIDED);
            }

            // Check if all pending requests are now responded
            $order = $infoRequest->getOrder();
            if ($order) {
                $this->checkAndTransitionToInformationProvided($order);
            }

            $this->logger->info('Client responded to info request', [
                'info_request_id' => $infoRequest->getId(),
                'user' => $authenticatedUser->getEmail()
            ]);

            if ($infoRequest->getId()) {
                $this->messageBus->dispatch(new OrderInfoRequestMessage(
                    $infoRequest->getId(),
                    'client_responded'
                ));
            }
        }

        // Admin follow-up (needs revision)
        if ($message->getSenderType() === OrderInfoMessage::SENDER_TYPE_ADMIN && $infoRequest) {
            if ($infoRequest->getStatus() === OrderInfoRequest::STATUS_RESPONDED) {
                $infoRequest->markAsNeedsRevision();

                $orderItem = $infoRequest->getOrderItem();
                if ($orderItem) {
                    $orderItem->setInfoStatus(OrderItem::INFO_STATUS_PENDING_INFO);
                }

                // Info requests work at item level; no order status transition needed

                if ($infoRequest->getId()) {
                    $this->messageBus->dispatch(new OrderInfoRequestMessage(
                        $infoRequest->getId(),
                        'revision_requested'
                    ));
                }
            }
        }
    }

    private function tryTransitionToMoreInfo(Order $order): void
    {
        // Legacy statuses don't include more_info — info requests work at item level only
    }

    private function checkAndTransitionToInformationProvided(Order $order): void
    {
        // Legacy statuses don't include information_provided — info requests work at item level only
        $this->logger->info('All info requests resolved for order', [
            'order_id' => $order->getId()
        ]);
    }

    private function checkAndTransitionOrder(Order $order): void
    {
        if ($order->allInfoRequestsAccepted()) {
            $this->logger->info('All info requests accepted for order', [
                'order_id' => $order->getId()
            ]);
        }
    }
}
