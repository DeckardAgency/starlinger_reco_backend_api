<?php

namespace App\Controller;

use App\Repository\OrderRepository;
use App\Repository\OrderItemRepository;
use Doctrine\ORM\EntityManagerInterface;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;

class OrderExcelController extends AbstractController
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger,
        private Security $security
    ) {}

    #[Route('/api/orders/export/excel', name: 'orders_export_excel', methods: ['GET'])]
    public function exportToExcel(
        OrderRepository $orderRepository,
        OrderItemRepository $orderItemRepository,
        Request $request
    ): StreamedResponse {
        try {
            ini_set('memory_limit', '512M');
            set_time_limit(300); // 5 minutes

            $spreadsheet = new Spreadsheet();

            // Create Orders sheet with items included
            $ordersSheet = $spreadsheet->getActiveSheet();
            $ordersSheet->setTitle('Orders');

            // Headers for orders
            $orderHeaders = [
                'Order ID',
                'Order Number',
                'User Email',
                'Status',
                'Archived',
                'Created At',
                'Product ID',
                'Product Name',
                'Product Code',
                'Quantity'
            ];

            $this->writeHeaders($ordersSheet, $orderHeaders);

            // Determine if user-scoped export is needed
            $currentUser = $this->security->getUser();
            $isAdmin = $currentUser && in_array('ROLE_ADMIN', $currentUser->getRoles(), true);

            // Read filter parameters from request
            $orderNumberFilter = $request->query->get('orderNumber');
            $statusFilters = $request->query->all('status');
            $clientCodeFilter = $request->query->get('user_client_code') ?? $request->query->get('user.client.code');

            // Fetch orders with user data and items (excluding draft orders)
            $qb = $orderRepository->createQueryBuilder('o')
                ->leftJoin('o.user', 'u')
                ->leftJoin('o.items', 'i')
                ->leftJoin('i.product', 'p')
                ->select('o', 'u', 'i', 'p')
                ->where('o.status != :draftStatus')
                ->setParameter('draftStatus', 'draft')
                ->orderBy('o.orderNumber', 'ASC')
                ->addOrderBy('i.id', 'ASC');

            if (!$isAdmin && $currentUser) {
                $qb->andWhere('u.id = :currentUserId')
                   ->setParameter('currentUserId', $currentUser->getId());
            }

            if ($orderNumberFilter) {
                $qb->andWhere('o.orderNumber LIKE :orderNumber')
                   ->setParameter('orderNumber', '%' . $orderNumberFilter . '%');
            }

            if (!empty($statusFilters)) {
                $qb->andWhere('o.status IN (:statuses)')
                   ->setParameter('statuses', $statusFilters);
            }

            if ($clientCodeFilter) {
                $qb->leftJoin('u.client', 'c')
                   ->andWhere('c.code = :clientCode')
                   ->setParameter('clientCode', $clientCodeFilter);
            }

            // Single result set reused for both sheets (this was previously fetched twice,
            // once per sheet, doubling hydration). toIterable() is not an option here:
            // Doctrine forbids iterating queries with fetch-joined to-many collections.
            $orders = $qb->getQuery()->getResult();

            $row = 2;
            $lastOrderId = null;
            $orderStartRow = 2;

            foreach ($orders as $order) {
                // Check if this is a new order
                if ($lastOrderId !== $order->getId()) {
                    // Apply merge styling to previous order if exists
                    if ($lastOrderId !== null && $row > $orderStartRow) {
                        $this->mergeOrderCells($ordersSheet, $orderStartRow, $row - 1);
                    }

                    $orderStartRow = $row;
                    $lastOrderId = $order->getId();
                }

                $user = $order->getUser();
                $items = $order->getItems();

                // If order has no items, write one row with empty item data
                if ($items->isEmpty()) {
                    $this->writeOrderRow($ordersSheet, $order, $user, null, $row);
                    $row++;
                } else {
                    // Write a row for each item
                    foreach ($items as $item) {
                        $this->writeOrderRow($ordersSheet, $order, $user, $item, $row);
                        $row++;
                    }
                }
            }

            // Apply merge styling to the last order
            if ($lastOrderId !== null && $row > $orderStartRow) {
                $this->mergeOrderCells($ordersSheet, $orderStartRow, $row - 1);
            }

            // Apply borders to the whole data range in ONE call instead of one
            // applyFromArray per row (PhpSpreadsheet's worst-case pattern).
            if ($row > 2) {
                $ordersSheet->getStyle('A2:J' . ($row - 1))->applyFromArray([
                    'borders' => [
                        'allBorders' => [
                            'borderStyle' => Border::BORDER_THIN,
                            'color' => ['rgb' => 'D9D9D9']
                        ]
                    ]
                ]);
            }

            // Add autofilter
            $ordersSheet->setAutoFilter('A1:J' . ($row - 1));

            // Create separate Order Items sheet for detailed view
            $itemsSheet = $spreadsheet->createSheet();
            $itemsSheet->setTitle('Order Items Detail');

            $itemHeaders = [
                'Order Number',
                'User Email',
                'Product ID',
                'Product Name',
                'Product Code',
                'Quantity',
                'Item Created At'
            ];

            $this->writeHeaders($itemsSheet, $itemHeaders);

            // Reuse the already-hydrated orders (same filters, same ordering: orders by
            // orderNumber, items by id) instead of re-fetching everything a second time.
            // Zebra fill style built once; per-row application is unavoidable for
            // alternating rows, but the array isn't rebuilt each iteration.
            $evenRowFill = [
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'F2F2F2']
                ]
            ];
            $row = 2;
            foreach ($orders as $order) {
                $user = $order->getUser();

                foreach ($order->getItems() as $orderItem) {
                    $product = $orderItem->getProduct();
                    if ($product === null) {
                        // The previous dedicated query inner-joined the product; keep that behavior.
                        continue;
                    }

                    $itemsSheet->setCellValue('A' . $row, $order->getOrderNumber());
                    $itemsSheet->setCellValue('B' . $row, $user ? $user->getEmail() : '');
                    $itemsSheet->setCellValue('C' . $row, $product->getId());
                    $itemsSheet->setCellValue('D' . $row, $product->getName());
                    $itemsSheet->setCellValue('E' . $row, $product->getPartNo() ?? '');
                    $itemsSheet->setCellValue('F' . $row, $orderItem->getQuantity());
                    $itemsSheet->setCellValue('G' . $row, $orderItem->getCreatedAt() ? $orderItem->getCreatedAt()->format('Y-m-d H:i:s') : '');

                    // Apply styling
                    if ($row % 2 == 0) {
                        $itemsSheet->getStyle('A' . $row . ':G' . $row)->applyFromArray($evenRowFill);
                    }

                    $row++;
                }
            }

            // Add autofilter to items sheet
            $itemsSheet->setAutoFilter('A1:G' . ($row - 1));

            // Create Summary sheet (only for bulk exports, not single-order)
            if (!$orderNumberFilter) {
                $summarySheet = $spreadsheet->createSheet();
                $summarySheet->setTitle('Summary');
                $this->createSummarySheet($summarySheet, $orderRepository, $isAdmin ? null : $currentUser);
            }

            // All cell data is copied into the spreadsheet; detach the managed entities
            // so the writer doesn't compete with the identity map for memory.
            $this->entityManager->clear();

            // Create the writer
            $writer = new Xlsx($spreadsheet);

            // Create a streamed response
            $response = new StreamedResponse();
            $response->setCallback(function () use ($writer) {
                $writer->save('php://output');
            });

            // Set response headers
            $filename = 'orders_' . date('Y-m-d_H-i-s') . '.xlsx';
            $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
            $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
            $response->headers->set('Cache-Control', 'max-age=0');

            return $response;

        } catch (\Exception $e) {
            $this->logger->error('Error exporting orders to Excel: ' . $e->getMessage());
            throw $e;
        }
    }

    private function writeOrderRow($sheet, $order, $user, $item, int $row): void
    {
        $sheet->setCellValue('A' . $row, $order->getId());
        $sheet->setCellValue('B' . $row, $order->getOrderNumber());
        $sheet->setCellValue('C' . $row, $user ? $user->getEmail() : '');
        $sheet->setCellValue('D' . $row, $order->getStatus());
        $sheet->setCellValue('E' . $row, $order->getIsArchived() ? 'Yes' : 'No');
        $sheet->setCellValue('F' . $row, $order->getCreatedAt() ? $order->getCreatedAt()->format('Y-m-d H:i:s') : '');

        if ($item) {
            $product = $item->getProduct();
            $sheet->setCellValue('G' . $row, $product->getId());
            $sheet->setCellValue('H' . $row, $product->getName());
            $sheet->setCellValue('I' . $row, $product->getPartNo() ?? '');
            $sheet->setCellValue('J' . $row, $item->getQuantity());
        } else {
            $sheet->setCellValue('G' . $row, '');
            $sheet->setCellValue('H' . $row, '');
            $sheet->setCellValue('I' . $row, '');
            $sheet->setCellValue('J' . $row, '');
        }

        // Borders are applied once over the full data range after the loop
        // (see exportToExcel) instead of per row here.
    }

    private function mergeOrderCells($sheet, int $startRow, int $endRow): void
    {
        // Merge cells for order-level data (columns A-F)
        $columnsToMerge = ['A', 'B', 'C', 'D', 'E', 'F'];

        foreach ($columnsToMerge as $column) {
            $sheet->mergeCells("{$column}{$startRow}:{$column}{$endRow}");
        }

        // Vertical centering for the whole merged block in one call
        // instead of one applyFromArray per column.
        $sheet->getStyle("A{$startRow}:F{$endRow}")->applyFromArray([
            'alignment' => [
                'vertical' => Alignment::VERTICAL_CENTER
            ]
        ]);

        // Apply alternating row color to the entire order block
        if ($startRow % 2 == 0) {
            $sheet->getStyle("A{$startRow}:J{$endRow}")->applyFromArray([
                'fill' => [
                    'fillType' => Fill::FILL_SOLID,
                    'startColor' => ['rgb' => 'F2F2F2']
                ]
            ]);
        }
    }

    private function createSummarySheet($sheet, OrderRepository $orderRepository, $filterUser = null): void
    {
        $sheet->setCellValue('A1', 'Order Export Summary');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(16);

        $sheet->setCellValue('A3', 'Export Date:');
        $sheet->setCellValue('B3', date('Y-m-d H:i:s'));

        // Calculate statistics (excluding draft orders)
        $totalQb = $orderRepository->createQueryBuilder('o')
            ->select('COUNT(o.id)')
            ->leftJoin('o.user', 'u')
            ->where('o.status != :draftStatus')
            ->setParameter('draftStatus', 'draft');

        if ($filterUser) {
            $totalQb->andWhere('u.id = :userId')->setParameter('userId', $filterUser->getId());
        }

        $totalOrders = $totalQb->getQuery()->getSingleScalarResult();

        // Count orders by status (excluding draft orders)
        $statusQb = $orderRepository->createQueryBuilder('o')
            ->select('o.status', 'COUNT(o.id) as count')
            ->leftJoin('o.user', 'u')
            ->where('o.status != :draftStatus')
            ->setParameter('draftStatus', 'draft')
            ->groupBy('o.status');

        if ($filterUser) {
            $statusQb->andWhere('u.id = :userId')->setParameter('userId', $filterUser->getId());
        }

        $statusStats = $statusQb->getQuery()->getResult();

        $sheet->setCellValue('A5', 'Total Orders:');
        $sheet->setCellValue('B5', $totalOrders);

        // Orders by status
        $sheet->setCellValue('A7', 'Orders by Status');
        $sheet->getStyle('A7')->getFont()->setBold(true);

        $row = 8;
        foreach ($statusStats as $stat) {
            $sheet->setCellValue('A' . $row, $stat['status']);
            $sheet->setCellValue('B' . $row, $stat['count']);
            $row++;
        }

        // Format the summary sheet
        $sheet->getColumnDimension('A')->setWidth(20);
        $sheet->getColumnDimension('B')->setWidth(15);
    }

    private function writeHeaders($sheet, array $headers): void
    {
        $headerStyle = [
            'font' => [
                'bold' => true,
                'color' => ['rgb' => 'FFFFFF']
            ],
            'fill' => [
                'fillType' => Fill::FILL_SOLID,
                'startColor' => ['rgb' => '4472C4']
            ],
            'alignment' => [
                'horizontal' => Alignment::HORIZONTAL_CENTER,
                'vertical' => Alignment::VERTICAL_CENTER
            ],
            'borders' => [
                'allBorders' => [
                    'borderStyle' => Border::BORDER_THIN,
                    'color' => ['rgb' => '000000']
                ]
            ]
        ];

        $column = 'A';
        foreach ($headers as $header) {
            $sheet->setCellValue($column . '1', $header);
            $sheet->getStyle($column . '1')->applyFromArray($headerStyle);
            $sheet->getColumnDimension($column)->setAutoSize(true);
            $column++;
        }
    }
}
