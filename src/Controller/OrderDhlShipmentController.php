<?php

namespace App\Controller;

use App\Entity\DhlShipment;
use App\Entity\Order;
use App\Entity\User;
use App\Repository\DhlShipmentRepository;
use App\Repository\OrderRepository;
use App\Service\Carrier\MyDhl\DhlBookingException;
use App\Service\Carrier\MyDhl\DhlShipmentService;
use App\Service\Carrier\MyDhl\MyDhlException;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\Serializer\Normalizer\NormalizerInterface;

/**
 * Admin DHL Express booking flow for an order (see DhlShipmentService).
 *
 * Errors are JSON {message, errors[], details[]}:
 *  - 409 order/shipment in the wrong state
 *  - 422 incomplete booking data (errors[]) or DHL rejected the request (details[])
 *  - 502 DHL unreachable
 */
#[IsGranted('ROLE_ADMIN')]
class OrderDhlShipmentController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly DhlShipmentRepository $shipmentRepository,
        private readonly DhlShipmentService $shipmentService,
        private readonly NormalizerInterface $normalizer,
    ) {
    }

    #[Route('/api/v1/orders/{id}/dhl-shipment/prefill', name: 'order_dhl_shipment_prefill', methods: ['GET'], requirements: ['id' => '\d+'])]
    public function prefill(int $id): JsonResponse
    {
        return new JsonResponse($this->shipmentService->prefill($this->findOrder($id)));
    }

    #[Route('/api/v1/orders/{id}/dhl-shipments', name: 'order_dhl_shipment_book', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function book(int $id, Request $request): JsonResponse
    {
        $order = $this->findOrder($id);

        return $this->handle(function () use ($order, $request) {
            $shipment = $this->shipmentService->book($order, $this->requestData($request), $this->currentUser());

            return $this->shipmentResponse($shipment, Response::HTTP_CREATED);
        });
    }

    #[Route('/api/v1/dhl_shipments/{id}/pickup', name: 'dhl_shipment_pickup_book', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function bookPickup(int $id, Request $request): JsonResponse
    {
        $shipment = $this->findShipment($id);

        return $this->handle(fn () => $this->shipmentResponse(
            $this->shipmentService->bookPickup($shipment, $this->requestData($request))
        ));
    }

    #[Route('/api/v1/dhl_shipments/{id}/pickup', name: 'dhl_shipment_pickup_cancel', methods: ['DELETE'], requirements: ['id' => '\d+'])]
    public function cancelPickup(int $id): JsonResponse
    {
        $shipment = $this->findShipment($id);

        return $this->handle(fn () => $this->shipmentResponse(
            $this->shipmentService->cancelPickup($shipment, $this->currentUser())
        ));
    }

    #[Route('/api/v1/dhl_shipments/{id}/cancel', name: 'dhl_shipment_cancel', methods: ['POST'], requirements: ['id' => '\d+'])]
    public function cancel(int $id): JsonResponse
    {
        $shipment = $this->findShipment($id);

        return $this->handle(fn () => $this->shipmentResponse(
            $this->shipmentService->cancel($shipment, $this->currentUser())
        ));
    }

    /**
     * The order fields the admin page shows change with every action — return them
     * so the UI doesn't save stale tracking/status back (same idea as tracking refresh).
     */
    private function shipmentResponse(DhlShipment $shipment, int $status = Response::HTTP_OK): JsonResponse
    {
        $order = $shipment->getOrder();

        return new JsonResponse([
            'shipment' => $this->normalizer->normalize($shipment, 'jsonld', ['groups' => ['dhl_shipment:read']]),
            'order' => [
                'id' => $order->getId(),
                'status' => $order->getStatus(),
                'trackingNumber' => $order->getTrackingNumber(),
                'trackingCarrier' => $order->getTrackingCarrier(),
                'trackingUrl' => $order->getTrackingUrl(),
                'dispatchedAt' => $order->getDispatchedAt()?->format(\DateTimeInterface::ATOM),
            ],
        ], $status);
    }

    private function handle(callable $action): JsonResponse
    {
        try {
            return $action();
        } catch (DhlBookingException $e) {
            return new JsonResponse(['message' => $e->getMessage(), 'errors' => $e->getErrors()], $e->getHttpStatus());
        } catch (MyDhlException $e) {
            // 4xx from DHL = bad data (show it); no status / 5xx = DHL down
            $status = $e->getHttpStatus() !== null && $e->getHttpStatus() < 500 ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_BAD_GATEWAY;

            return new JsonResponse([
                'message' => 'DHL: ' . $e->getMessage(),
                'details' => $e->getDetails(),
                'dhlReference' => $e->getMessageReference(),
            ], $status);
        }
    }

    private function requestData(Request $request): array
    {
        try {
            $data = json_decode($request->getContent() ?: '{}', true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new BadRequestHttpException('Invalid JSON body.');
        }

        return is_array($data) ? $data : [];
    }

    private function currentUser(): User
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException();
        }

        return $user;
    }

    private function findOrder(int $id): Order
    {
        return $this->orderRepository->find($id) ?? throw new NotFoundHttpException('Order not found.');
    }

    private function findShipment(int $id): DhlShipment
    {
        return $this->shipmentRepository->find($id) ?? throw new NotFoundHttpException('DHL shipment not found.');
    }
}
