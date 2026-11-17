<?php

namespace App\Controller;

use App\Repository\DhlShipmentRepository;
use App\Service\Carrier\MyDhl\DhlLabelStorage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Serves a stored DHL shipment document (label, commercial invoice) as PDF.
 * Files live outside public/ (they contain customer addresses).
 */
#[IsGranted('ROLE_ADMIN')]
class DhlShipmentDocumentController extends AbstractController
{
    public function __construct(
        private readonly DhlShipmentRepository $shipmentRepository,
        private readonly DhlLabelStorage $labelStorage,
    ) {
    }

    #[Route(
        path: '/api/v1/dhl_shipments/{id}/documents/{type}',
        name: 'dhl_shipment_document',
        methods: ['GET'],
        requirements: ['id' => '\d+', 'type' => '[a-zA-Z]+']
    )]
    public function __invoke(int $id, string $type): BinaryFileResponse
    {
        $shipment = $this->shipmentRepository->find($id);
        $fileName = $shipment?->getDocumentFile($type);
        $path = $fileName ? $this->labelStorage->getPath($fileName) : null;

        if (!$path) {
            throw new NotFoundHttpException('Document not found.');
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', 'application/pdf');
        // inline: the admin UI opens it in a new tab to print
        $response->setContentDisposition(
            ResponseHeaderBag::DISPOSITION_INLINE,
            sprintf('dhl-%s-%s.pdf', $type, $shipment->getAwb())
        );

        return $response;
    }
}
