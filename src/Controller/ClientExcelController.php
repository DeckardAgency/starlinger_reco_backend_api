<?php

namespace App\Controller;

use App\Repository\ClientRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Psr\Log\LoggerInterface;

class ClientExcelController extends AbstractController
{
    public function __construct(
        private LoggerInterface $logger
    ) {}

    #[Route('/api/clients/export/excel', name: 'clients_export_excel', methods: ['GET'])]
    #[IsGranted('ROLE_ADMIN')]
    public function exportToExcel(ClientRepository $clientRepository): StreamedResponse
    {
        ini_set('memory_limit', '256M');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Clients');

        $headers = [
            'ID', 'Name', 'Code', 'Email', 'Phone', 'Address',
            'VAT Number', 'Account Type', 'Active', 'Archived',
            'Legal Entity', 'Users Count', 'Created At'
        ];
        $this->writeHeaders($sheet, $headers);

        $clients = $clientRepository->createQueryBuilder('c')
            ->leftJoin('c.users', 'u')
            ->select('c', 'u')
            ->orderBy('c.name', 'ASC')
            ->getQuery()
            ->getResult();

        $row = 2;
        foreach ($clients as $client) {
            $sheet->setCellValue('A' . $row, $client->getId());
            $sheet->setCellValue('B' . $row, $client->getName());
            $sheet->setCellValue('C' . $row, $client->getCode());
            $sheet->setCellValue('D' . $row, $client->getEmail());
            $sheet->setCellValue('E' . $row, $client->getPhoneNumber());
            $sheet->setCellValue('F' . $row, $client->getAddress());
            $sheet->setCellValue('G' . $row, $client->getVatNumber());
            $sheet->setCellValue('H' . $row, $client->getAccountType());
            $sheet->setCellValue('I' . $row, $client->getIsActive() ? 'Yes' : 'No');
            $sheet->setCellValue('J' . $row, $client->getIsArchived() ? 'Yes' : 'No');
            $sheet->setCellValue('K' . $row, $client->getIsLegalEntity() ? 'Yes' : 'No');
            $sheet->setCellValue('L' . $row, $client->getUsers()->count());
            $sheet->setCellValue('M' . $row, $client->getCreatedAt()?->format('Y-m-d H:i:s') ?? '');

            if ($row % 2 == 0) {
                $sheet->getStyle('A' . $row . ':M' . $row)->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'F2F2F2']
                    ]
                ]);
            }

            $row++;
        }

        $sheet->setAutoFilter('A1:M' . ($row - 1));

        $writer = new Xlsx($spreadsheet);
        $response = new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        });

        $filename = 'clients_' . date('Y-m-d_H-i-s') . '.xlsx';
        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
        $response->headers->set('Cache-Control', 'max-age=0');

        return $response;
    }

    private function writeHeaders($sheet, array $headers): void
    {
        $headerStyle = [
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '4472C4']],
            'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER],
            'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['rgb' => '000000']]]
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
