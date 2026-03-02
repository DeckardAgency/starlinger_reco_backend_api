<?php

namespace App\Controller;

use App\Entity\User;
use App\Service\DashboardService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api')]
class DashboardController extends AbstractController
{
    public function __construct(
        private DashboardService $dashboardService,
        private Security $security
    ) {}

    #[Route('/dashboard/performance-overview', name: 'api_dashboard_performance_overview', methods: ['GET'])]
    public function performanceOverview(Request $request): JsonResponse
    {
        $startDate = $request->query->get('startDate');
        $endDate = $request->query->get('endDate');

        if (!$startDate || !$endDate) {
            $startDate = new \DateTime('first day of January this year');
            $endDate = new \DateTime('last day of December this year');
        } else {
            $startDate = new \DateTime($startDate);
            $endDate = new \DateTime($endDate);
        }

        $clientId = $this->getClientIdForCurrentUser();
        $response = $this->dashboardService->getPerformanceOverview($startDate, $endDate, $clientId);

        return $this->json($response);
    }

    #[Route('/v1/dashboard/order-status-distribution', name: 'api_dashboard_order_status_distribution', methods: ['GET'])]
    public function orderStatusDistribution(): JsonResponse
    {
        $clientId = $this->getClientIdForCurrentUser();
        $data = $this->dashboardService->getOrderStatusDistribution($clientId);
        return $this->json($data);
    }

    private function getClientIdForCurrentUser(): ?int
    {
        $user = $this->security->getUser();

        if (!$user instanceof User) {
            return null;
        }

        // Admins see all data
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return null;
        }

        return $user->getClient()?->getId();
    }
}
