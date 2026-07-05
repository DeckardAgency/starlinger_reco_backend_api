<?php

namespace App\Controller;

use App\Repository\OrderRepository;
use App\Service\TrackingService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\SerializerInterface;

#[IsGranted('ROLE_ADMIN')]
class OrderTrackingController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly TrackingService $trackingService,
        private readonly SerializerInterface $serializer,
    ) {
    }

    #[Route(
        path: '/api/v1/orders/{id}/tracking/refresh',
        name: 'order_tracking_refresh',
        methods: ['POST'],
        requirements: ['id' => '\d+']
    )]
    public function refresh(int $id): JsonResponse
    {
        $order = $this->orderRepository->find($id);
        if (!$order) {
            throw new NotFoundHttpException('Order not found.');
        }

        $createdCount = $this->trackingService->refreshOrder($order);
        $events = $order->getTrackingEvents()->toArray();

        $payload = $this->serializer->normalize($events, 'jsonld', [
            'groups' => ['tracking_event:read'],
        ]);

        return new JsonResponse([
            'created' => $createdCount,
            // Tracking can advance the order status (e.g. carrier reports delivery);
            // return it so the UI can update its form state and not save a stale status back.
            'orderStatus' => $order->getStatus(),
            'events' => $payload,
        ], Response::HTTP_OK);
    }
}
