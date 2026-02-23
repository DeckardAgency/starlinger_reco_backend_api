<?php

namespace App\Controller;

use App\Repository\UserRepository;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Annotation\Route;
use Psr\Log\LoggerInterface;

class UserExcelController extends AbstractController
{
    public function __construct(
        private LoggerInterface $logger
    ) {}

    #[Route('/api/users/export/excel', name: 'users_export_excel', methods: ['GET'])]
    public function exportToExcel(UserRepository $userRepository): StreamedResponse
    {
        ini_set('memory_limit', '256M');

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Users');

        $headers = [
            'ID', 'Email', 'First Name', 'Last Name', 'Phone',
            'Roles', 'Client', 'Active', 'Orders Count', 'Created At'
        ];
        $this->writeHeaders($sheet, $headers);

        $users = $userRepository->createQueryBuilder('u')
            ->leftJoin('u.client', 'c')
            ->leftJoin('u.orders', 'o')
            ->select('u', 'c')
            ->addSelect('COUNT(o.id) as orderCount')
            ->groupBy('u.id')
            ->orderBy('u.lastName', 'ASC')
            ->getQuery()
            ->getResult();

        $row = 2;
        foreach ($users as $result) {
            $user = is_array($result) ? $result[0] : $result;
            $orderCount = is_array($result) ? ($result['orderCount'] ?? 0) : 0;

            $sheet->setCellValue('A' . $row, $user->getId());
            $sheet->setCellValue('B' . $row, $user->getEmail());
            $sheet->setCellValue('C' . $row, $user->getFirstName());
            $sheet->setCellValue('D' . $row, $user->getLastName());
            $sheet->setCellValue('E' . $row, $user->getPhoneNumber());
            $sheet->setCellValue('F' . $row, implode(', ', $user->getRoles()));
            $sheet->setCellValue('G' . $row, $user->getClient()?->getName() ?? '');
            $sheet->setCellValue('H' . $row, $user->getIsActive() ? 'Yes' : 'No');
            $sheet->setCellValue('I' . $row, $orderCount);
            $sheet->setCellValue('J' . $row, $user->getCreatedAt()?->format('Y-m-d H:i:s') ?? '');

            if ($row % 2 == 0) {
                $sheet->getStyle('A' . $row . ':J' . $row)->applyFromArray([
                    'fill' => [
                        'fillType' => Fill::FILL_SOLID,
                        'startColor' => ['rgb' => 'F2F2F2']
                    ]
                ]);
            }

            $row++;
        }

        $sheet->setAutoFilter('A1:J' . ($row - 1));

        $writer = new Xlsx($spreadsheet);
        $response = new StreamedResponse(function () use ($writer) {
            $writer->save('php://output');
        });

        $filename = 'users_' . date('Y-m-d_H-i-s') . '.xlsx';
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
