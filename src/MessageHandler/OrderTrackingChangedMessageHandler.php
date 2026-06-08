<?php

namespace App\MessageHandler;

use App\Message\OrderTrackingChangedMessage;
use App\Repository\OrderRepository;
use App\Repository\TrackingEventRepository;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Twig\Environment;

#[AsMessageHandler]
class OrderTrackingChangedMessageHandler
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly TrackingEventRepository $trackingEventRepository,
        private readonly MailerInterface $mailer,
        private readonly Environment $twig,
        private readonly LoggerInterface $logger,
        private readonly string $senderEmail = 'noreply@example.com',
    ) {
    }

    public function __invoke(OrderTrackingChangedMessage $message): void
    {
        $order = $this->orderRepository->find($message->orderId);
        $event = $this->trackingEventRepository->find($message->eventId);

        if (!$order || !$event) {
            $this->logger->warning('OrderTrackingChangedMessage: order or event not found', [
                'order_id' => $message->orderId,
                'event_id' => $message->eventId,
            ]);
            return;
        }

        $user = $order->getUser();
        $email = $user?->getEmail();
        if (!$email) {
            return;
        }

        try {
            $html = $this->twig->render('emails/customer/order_tracking_update.html.twig', [
                'order' => $order,
                'user' => $user,
                'event' => $event,
            ]);

            $msg = (new Email())
                ->from(new Address($this->senderEmail, 'Starlinger Orders'))
                ->to(new Address($email, $user->getFullName() ?? ''))
                ->subject(sprintf('Order #%s: tracking update', $order->getOrderNumber() ?? $order->getId()))
                ->html($html);

            $this->mailer->send($msg);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to send tracking-change email', [
                'order_id' => $order->getId(),
                'event_id' => $event->getId(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
