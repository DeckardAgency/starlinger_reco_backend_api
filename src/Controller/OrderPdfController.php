<?php

namespace App\Controller;

use App\Repository\OrderRepository;
use Knp\Bundle\SnappyBundle\Snappy\Response\PdfResponse;
use Knp\Snappy\Pdf;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Twig\Environment;

#[AsController]
class OrderPdfController extends AbstractController
{
    public function __construct(
        private readonly OrderRepository $orderRepository,
        private readonly Environment $twig,
        private readonly Pdf $knpSnappyPdf
    ) {
    }

    public function __invoke(string $id): Response
    {
        $orderId = (int) $id;
        if ($orderId <= 0) {
            throw new NotFoundHttpException('Invalid order ID format');
        }

        $order = $this->orderRepository->find($orderId);

        if (!$order) {
            throw new NotFoundHttpException('Order not found');
        }

        $this->denyAccessUnlessGranted('VIEW', $order);

        $html = $this->twig->render('pdf/order.html.twig', [
            'order' => $order,
            'generatedAt' => new \DateTime(),
        ]);

        $filename = sprintf('order_%s_%s.pdf',
            $order->getOrderNumber(),
            date('Y-m-d_His')
        );

        return new PdfResponse(
            $this->knpSnappyPdf->getOutputFromHtml($html, [
                'encoding' => 'utf-8',
                'enable-local-file-access' => true,
                'page-size' => 'A4',
                'margin-top' => '10mm',
                'margin-bottom' => '10mm',
                'margin-left' => '10mm',
                'margin-right' => '10mm',
            ]),
            $filename
        );
    }
}
