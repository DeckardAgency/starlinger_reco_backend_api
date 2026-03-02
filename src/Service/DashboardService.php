<?php

namespace App\Service;

use App\Entity\Order;
use Doctrine\ORM\EntityManagerInterface;

class DashboardService
{
    public function __construct(
        private EntityManagerInterface $entityManager
    ) {}

    public function getPerformanceOverview(\DateTime $startDate, \DateTime $endDate, ?int $clientId = null): array
    {
        // Normalize dates: start at beginning of day, end at end of day
        $normalizedStartDate = clone $startDate;
        $normalizedStartDate->setTime(0, 0, 0);

        $normalizedEndDate = clone $endDate;
        $normalizedEndDate->setTime(23, 59, 59);

        // Get current period data
        $currentData = $this->getPerformanceData($normalizedStartDate, $normalizedEndDate, $clientId);

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

        $previousData = $this->getPerformanceData($previousStartDate, $previousEndDate, $clientId);

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

    private function getPerformanceData(\DateTime $startDate, \DateTime $endDate, ?int $clientId = null): array
    {
        $conn = $this->entityManager->getConnection();

        $joinClause = $clientId !== null ? 'JOIN `user` u ON o.user_id = u.id' : '';
        $clientWhere = $clientId !== null ? 'AND u.client_id = :clientId' : '';

        $sql = "
            SELECT
                -- Shop orders (non-draft, non-cancelled)
                (SELECT COUNT(*)
                 FROM `order` o {$joinClause}
                 WHERE o.created_at >= :startDate
                 AND o.created_at <= :endDate
                 AND o.status NOT IN (:draftStatus, :cancelledStatus)
                 {$clientWhere}) as shop_orders,

                -- Active carts (draft orders)
                (SELECT COUNT(*)
                 FROM `order` o {$joinClause}
                 WHERE o.created_at >= :startDate
                 AND o.created_at <= :endDate
                 AND o.status = :draftStatus
                 {$clientWhere}) as active_carts,

                -- Completed carts (delivered orders)
                (SELECT COUNT(*)
                 FROM `order` o {$joinClause}
                 WHERE o.created_at >= :startDate
                 AND o.created_at <= :endDate
                 AND o.status = :deliveredStatus
                 {$clientWhere}) as completed_carts,

                -- Total shop revenue
                (SELECT COALESCE(SUM(o.total_amount), 0)
                 FROM `order` o {$joinClause}
                 WHERE o.created_at >= :startDate
                 AND o.created_at <= :endDate
                 AND o.status NOT IN (:draftStatus, :cancelledStatus)
                 {$clientWhere}) as total_shop_revenue,

                -- Cancelled orders revenue
                (SELECT COALESCE(SUM(o.total_amount), 0)
                 FROM `order` o {$joinClause}
                 WHERE o.created_at >= :startDate
                 AND o.created_at <= :endDate
                 AND o.status = :cancelledStatus
                 {$clientWhere}) as cancelled_orders_revenue
        ";

        $params = [
            'startDate' => $startDate->format('Y-m-d H:i:s'),
            'endDate' => $endDate->format('Y-m-d H:i:s'),
            'draftStatus' => Order::STATUS_DRAFT,
            'cancelledStatus' => Order::STATUS_CANCELED,
            'deliveredStatus' => Order::STATUS_DELIVERED
        ];

        if ($clientId !== null) {
            $params['clientId'] = $clientId;
        }

        $stmt = $conn->prepare($sql);
        $result = $stmt->executeQuery($params)->fetchAssociative();

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
    public function getOrderStatusDistribution(?int $clientId = null): array
    {
        $conn = $this->entityManager->getConnection();

        $joinClause = $clientId !== null ? 'JOIN `user` u ON o.user_id = u.id' : '';
        $clientWhere = $clientId !== null ? 'AND u.client_id = :clientId' : '';

        $sql = "
            SELECT
                o.status,
                COUNT(*) as count
            FROM `order` o
            {$joinClause}
            WHERE o.status != :draftStatus
            {$clientWhere}
            GROUP BY o.status
            ORDER BY count DESC
        ";

        $params = ['draftStatus' => Order::STATUS_DRAFT];
        if ($clientId !== null) {
            $params['clientId'] = $clientId;
        }

        $results = $conn->executeQuery($sql, $params)->fetchAllAssociative();

        $statusLabels = [
            Order::STATUS_NEW => 'New',
            Order::STATUS_IN_PROCESS => 'In process',
            Order::STATUS_WAITING_FOR_PAYMENT => 'Waiting for payment',
            Order::STATUS_READY_FOR_SHIPMENT => 'Ready for shipment',
            Order::STATUS_SHIPPED => 'Shipped',
            Order::STATUS_DELIVERED => 'Delivered',
            Order::STATUS_CANCELED => 'Canceled',
            Order::STATUS_REVERSAL => 'Reversal'
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
