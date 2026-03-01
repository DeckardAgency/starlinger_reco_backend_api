<?php

namespace App\Service;

use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;

class DashboardService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    public function getPerformanceOverview(\DateTime $startDate, \DateTime $endDate): array
    {
        // Normalize dates: start at beginning of day, end at end of day
        $normalizedStartDate = clone $startDate;
        $normalizedStartDate->setTime(0, 0, 0);

        $normalizedEndDate = clone $endDate;
        $normalizedEndDate->setTime(23, 59, 59);

        // Get current period data
        $currentData = $this->getPerformanceData($normalizedStartDate, $normalizedEndDate);

        // Calculate the number of days in the current period
        $daysDiff = (int) $normalizedStartDate->diff($normalizedEndDate)->days;
        // Ensure at least 1 day for comparison
        $daysDiff = max($daysDiff, 1);

        // Get previous period data for comparison (same number of days before start date)
        $previousEndDate = clone $normalizedStartDate;
        $previousEndDate->modify('-1 day');
        $previousEndDate->setTime(23, 59, 59);

        $previousStartDate = clone $previousEndDate;
        $previousStartDate->modify("-{$daysDiff} days");
        $previousStartDate->setTime(0, 0, 0);

        $previousData = $this->getPerformanceData($previousStartDate, $previousEndDate);

        // Build response with percentage changes
        return [
            'period' => [
                'start' => $normalizedStartDate->format('Y-m-d'),
                'end' => $normalizedEndDate->format('Y-m-d')
            ],
            'shopOrders' => [
                'value' => $currentData['shopOrders'],
                'percentageChange' => $this->calculatePercentageChange(
                    $previousData['shopOrders'],
                    $currentData['shopOrders']
                ),
                'trend' => $this->getTrend($previousData['shopOrders'], $currentData['shopOrders'])
            ],
            'activeCarts' => [
                'value' => $currentData['activeCarts'],
                'percentageChange' => $this->calculatePercentageChange(
                    $previousData['activeCarts'],
                    $currentData['activeCarts']
                ),
                'trend' => $this->getTrend($previousData['activeCarts'], $currentData['activeCarts'])
            ],
            'completedCarts' => [
                'value' => $currentData['completedCarts'],
                'percentageChange' => $this->calculatePercentageChange(
                    $previousData['completedCarts'],
                    $currentData['completedCarts']
                ),
                'trend' => $this->getTrend($previousData['completedCarts'], $currentData['completedCarts'])
            ],
            'totalShopRevenue' => [
                'value' => $currentData['totalShopRevenue'],
                'formatted' => number_format($currentData['totalShopRevenue'], 2, ',', '.') . ' €',
                'percentageChange' => $this->calculatePercentageChange(
                    $previousData['totalShopRevenue'],
                    $currentData['totalShopRevenue']
                ),
                'trend' => $this->getTrend($previousData['totalShopRevenue'], $currentData['totalShopRevenue'])
            ],
            'cancelledOrdersRevenue' => [
                'value' => $currentData['cancelledOrdersRevenue'],
                'formatted' => number_format($currentData['cancelledOrdersRevenue'], 2, ',', '.') . ' €',
                'percentageChange' => $this->calculatePercentageChange(
                    $previousData['cancelledOrdersRevenue'],
                    $currentData['cancelledOrdersRevenue']
                ),
                'trend' => $this->getTrend($previousData['cancelledOrdersRevenue'], $currentData['cancelledOrdersRevenue'])
            ]
        ];
    }

    private function getPerformanceData(\DateTime $startDate, \DateTime $endDate): array
    {
        $conn = $this->entityManager->getConnection();

        // Use native SQL for better performance with a single query
        $sql = '
            SELECT 
                -- Shop orders (non-draft, non-cancelled)
                (SELECT COUNT(*) 
                 FROM `order` 
                 WHERE created_at >= :startDate 
                 AND created_at <= :endDate
                 AND status NOT IN (:draftStatus, :cancelledStatus)) as shop_orders,

                -- Active carts (draft orders)
                (SELECT COUNT(*) 
                 FROM `order` 
                 WHERE created_at >= :startDate 
                 AND created_at <= :endDate
                 AND status = :draftStatus) as active_carts,
                
                -- Completed carts
                (SELECT COUNT(*) 
                 FROM `order` 
                 WHERE created_at >= :startDate 
                 AND created_at <= :endDate
                 AND status = :completedStatus) as completed_carts,
                
                -- Total shop revenue
                (SELECT COALESCE(SUM(total_amount), 0) 
                 FROM `order` 
                 WHERE created_at >= :startDate 
                 AND created_at <= :endDate
                 AND status NOT IN (:draftStatus, :cancelledStatus)) as total_shop_revenue,
                
                -- Cancelled orders revenue
                (SELECT COALESCE(SUM(total_amount), 0) 
                 FROM `order` 
                 WHERE created_at >= :startDate 
                 AND created_at <= :endDate
                 AND status = :cancelledStatus) as cancelled_orders_revenue
        ';

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery([
            'startDate' => $startDate->format('Y-m-d H:i:s'),
            'endDate' => $endDate->format('Y-m-d H:i:s'),
            'draftStatus' => Order::STATUS_DRAFT,
            'cancelledStatus' => Order::STATUS_CANCELED,
            'completedStatus' => Order::STATUS_COMPLETED
        ])->fetchAssociative();

        return [
            'shopOrders' => (int) $result['shop_orders'],
            'activeCarts' => (int) $result['active_carts'],
            'completedCarts' => (int) $result['completed_carts'],
            'totalShopRevenue' => (float) $result['total_shop_revenue'],
            'cancelledOrdersRevenue' => (float) $result['cancelled_orders_revenue']
        ];
    }

    private function calculatePercentageChange(float $oldValue, float $newValue): float
    {
        if ($oldValue == 0) {
            return $newValue > 0 ? 100.0 : 0.0;
        }

        return round((($newValue - $oldValue) / $oldValue) * 100, 2);
    }

    private function getTrend(float $oldValue, float $newValue): string
    {
        if ($newValue > $oldValue) {
            return 'up';
        } elseif ($newValue < $oldValue) {
            return 'down';
        }

        return 'neutral';
    }

    /**
     * Get order status distribution for dashboard chart
     */
    public function getOrderStatusDistribution(): array
    {
        $conn = $this->entityManager->getConnection();

        $sql = '
            SELECT
                status,
                COUNT(*) as count
            FROM `order`
            WHERE status != :draftStatus
            GROUP BY status
            ORDER BY count DESC
        ';

        $results = $conn->executeQuery($sql, ['draftStatus' => Order::STATUS_DRAFT])->fetchAllAssociative();

        $statusLabels = [
            'pending' => 'Pending',
            'processing' => 'Processing',
            'dispatched' => 'Dispatched',
            Order::STATUS_SUBMITTED => 'Submitted',
            Order::STATUS_IN_REVIEW => 'In Review',
            Order::STATUS_MORE_INFO => 'More Info Needed',
            Order::STATUS_INFORMATION_PROVIDED => 'Info Provided',
            Order::STATUS_IN_PROGRESS => 'In Progress',
            Order::STATUS_COMPLETED => 'Completed',
            Order::STATUS_CANCELED => 'Canceled'
        ];

        $distribution = [];
        $total = 0;

        foreach ($results as $row) {
            $status = $row['status'];
            $count = (int) $row['count'];
            $total += $count;

            $distribution[] = [
                'status' => $status,
                'label' => $statusLabels[$status] ?? ucfirst(str_replace('_', ' ', $status)),
                'count' => $count
            ];
        }

        return [
            'distribution' => $distribution,
            'total' => $total
        ];
    }
}
