<?php

namespace App\Controller;

use App\Repository\CountryRepository;
use App\Repository\DeliveryPriceRepository;
use App\Repository\DeliveryTypeRepository;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

class DeliveryCostController extends AbstractController
{
    public function __construct(
        private DeliveryPriceRepository $deliveryPriceRepository,
        private DeliveryTypeRepository $deliveryTypeRepository,
        private CountryRepository $countryRepository
    ) {}

    #[Route('/api/v1/delivery-cost/calculate', name: 'delivery_cost_calculate', methods: ['GET'])]
    public function calculate(Request $request): JsonResponse
    {
        $countryId = $request->query->getInt('countryId');
        $weight = (float) $request->query->get('weight', '0');
        $deliveryTypeId = $request->query->getInt('deliveryTypeId');

        if ($countryId <= 0) {
            return $this->json(['error' => 'countryId is required'], 400);
        }

        // Look up the country to get its DHL zone
        $country = $this->countryRepository->find($countryId);
        if (!$country) {
            return $this->json(['error' => 'Country not found'], 404);
        }

        $dhlZone = $country->getDhlZone();
        if ($dhlZone === null) {
            return $this->json([
                'deliveryCost' => 0,
                'deliveryDays' => null,
                'message' => 'No DHL zone configured for this country'
            ]);
        }

        // Get delivery type - use specified or default
        $deliveryType = null;
        if ($deliveryTypeId > 0) {
            $deliveryType = $this->deliveryTypeRepository->find($deliveryTypeId);
        }
        if (!$deliveryType) {
            $deliveryType = $this->deliveryTypeRepository->findDefault();
        }
        if (!$deliveryType) {
            // Fallback: first active delivery type
            $activeTypes = $this->deliveryTypeRepository->findDeliveryOnly();
            $deliveryType = $activeTypes[0] ?? null;
        }

        if (!$deliveryType) {
            return $this->json([
                'deliveryCost' => 0,
                'deliveryDays' => null,
                'message' => 'No delivery type available'
            ]);
        }

        // Find the matching price tier
        $deliveryPrice = $this->deliveryPriceRepository->findPriceForWeightAndZone(
            $deliveryType,
            $weight,
            $dhlZone
        );

        if (!$deliveryPrice) {
            return $this->json([
                'deliveryCost' => 0,
                'deliveryDays' => null,
                'deliveryTypeName' => $deliveryType->getName(),
                'dhlZone' => $dhlZone,
                'weight' => $weight,
                'message' => 'No price found for this weight and zone'
            ]);
        }

        // Calculate the delivery cost
        $baseCost = (float) $deliveryPrice->getPriceBase();
        $totalCost = $baseCost;

        // Apply step pricing if weight exceeds stepStartsAt
        $stepStartsAt = $deliveryPrice->getStepStartsAt() ? (float) $deliveryPrice->getStepStartsAt() : null;
        $forEveryNextSize = $deliveryPrice->getForEveryNextSize() ? (float) $deliveryPrice->getForEveryNextSize() : null;
        $priceBaseStep = $deliveryPrice->getPriceBaseStep() ? (float) $deliveryPrice->getPriceBaseStep() : null;

        if ($stepStartsAt !== null && $forEveryNextSize !== null && $priceBaseStep !== null
            && $weight > $stepStartsAt && $forEveryNextSize > 0) {
            $excessWeight = $weight - $stepStartsAt;
            $steps = ceil($excessWeight / $forEveryNextSize);
            $totalCost += $steps * $priceBaseStep;
        }

        return $this->json([
            'deliveryCost' => round($totalCost, 2),
            'deliveryDays' => $deliveryPrice->getDeliveryDays(),
            'deliveryTypeName' => $deliveryType->getName(),
            'dhlZone' => $dhlZone,
            'weight' => $weight
        ]);
    }
}
