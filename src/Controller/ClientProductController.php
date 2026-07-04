<?php

namespace App\Controller;

use App\Repository\ClientProductPriceRepository;
use App\Repository\ClientRepository;
use App\Repository\ProductRepository;
use App\Service\DiscountResolver;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Serializer\SerializerInterface;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

#[Route('/api/v1')]
class ClientProductController extends AbstractController
{
    public function __construct(
        private ClientRepository $clientRepository,
        private ProductRepository $productRepository,
        private ClientProductPriceRepository $clientProductPriceRepository,
        private SerializerInterface $serializer,
        private EntityManagerInterface $entityManager,
        private DiscountResolver $discountResolver,
        private LoggerInterface $logger
    ) {}

    #[Route('/client/{clientId}/products/debug-relations', name: 'api_client_products_debug_relations', methods: ['GET'])]
    public function debugRelations(string $clientId): JsonResponse
    {
        // Only allow debug in development environment
        if ($this->getParameter('kernel.environment') === 'prod') {
            return $this->json(['error' => 'Debug endpoint not available in production'], Response::HTTP_FORBIDDEN);
        }

        $this->assertClientAccess($clientId);

        try {
            $client = $this->findClientOrThrow($clientId);
            $debugInfo = $this->generateDebugInfo($client);

            return $this->json($debugInfo);
        } catch (\Exception $e) {
            $this->logger->error('debugRelations failed', ['exception' => $e]);
            return $this->json(['error' => 'Debug failed.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/client/{clientId}/products', name: 'api_client_products', methods: ['GET'])]
    public function getClientProducts(string $clientId, Request $request): JsonResponse
    {
        $this->assertClientAccess($clientId);
        try {
            $client = $this->findClientOrThrow($clientId);
            $filters = $this->extractFilters($request);

            // Filtering + pagination in SQL; only the requested page is hydrated and formatted.
            [$pagedClientPrices, $totalItems] = $this->clientProductPriceRepository->findFilteredPageForClient(
                $client,
                $filters['search'],
                $filters['productName'],
                $filters['productPartNo'],
                $filters['page'],
                $filters['itemsPerPage']
            );

            $pagedProducts = $this->formatProductsResponse($pagedClientPrices, $client);

            $response = $this->buildResponse($clientId, $pagedProducts, $totalItems, $filters);

            return $this->json($response);
        } catch (\Exception $e) {
            $this->logger->error('getClientProducts failed', ['exception' => $e]);
            return $this->json(['error' => 'Unable to load client products.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    #[Route('/client/products', name: 'api_current_client_products', methods: ['GET'])]
    public function getCurrentClientProducts(Request $request): JsonResponse
    {
        $user = $this->getUser();

        if (!$user || !method_exists($user, 'getClient') || !$user->getClient()) {
            return $this->json(['error' => 'User not associated with any client'], Response::HTTP_FORBIDDEN);
        }

        return $this->getClientProducts($user->getClient()->getId(), $request);
    }

    #[Route('/client/{clientId}/products/{productId}/price', name: 'api_set_client_product_price', methods: ['POST'])]
    public function setClientProductPrice(string $clientId, string $productId, Request $request): JsonResponse
    {
        // Authorization runs outside the try so denials surface as 401/403, not a masked 500.
        $this->checkPermissions();
        $this->assertClientAccess($clientId);

        try {
            $client = $this->findClientOrThrow($clientId);
            $product = $this->findProductOrThrow($productId);

            $data = $this->validatePriceData($request);

            $clientProductPrice = $this->createOrUpdateClientProductPrice($client, $product, $data);

            $responseData = $this->formatClientProductPriceResponse($clientProductPrice);
            $statusCode = $clientProductPrice->getId() ? Response::HTTP_OK : Response::HTTP_CREATED;

            return $this->json($responseData, $statusCode);
        } catch (\Exception $e) {
            $this->logger->error('setClientProductPrice failed', ['exception' => $e]);
            return $this->json(['error' => 'Unable to set client product price.'], Response::HTTP_INTERNAL_SERVER_ERROR);
        }
    }

    private function findClientOrThrow(string $clientId): object
    {
        $client = $this->clientRepository->find((int) $clientId);

        if (!$client) {
            throw new \RuntimeException('Client not found');
        }

        return $client;
    }

    private function findProductOrThrow(string $productId): object
    {
        $product = $this->productRepository->find((int) $productId);

        if (!$product) {
            throw new \RuntimeException('Product not found');
        }

        return $product;
    }

    private function extractFilters(Request $request): array
    {
        return [
            'search' => $request->query->get('search'),
            'productName' => $request->query->get('product.name')
                ?: $request->query->get('product_name'),
            'productPartNo' => $request->query->get('product.partNo')
                ?: $request->query->get('product_partNo'),
            'page' => max(1, (int) ($request->query->get('page') ?: 1)),
            'itemsPerPage' => min(100, max(1, (int) ($request->query->get('itemsPerPage') ?: 30))),
        ];
    }


    private function formatProductsResponse(array $clientPrices, object $client): array
    {
        $formattedProducts = [];

        foreach ($clientPrices as $clientProductPrice) {
            $product = $clientProductPrice->getProduct();

            // Resolve discount (campaign or product-level)
            $effectivePrice = $clientProductPrice->getEffectivePrice();
            $resolved = $this->discountResolver->resolveDiscount($product, $client);
            $discountedPrice = $effectivePrice;
            $hasDiscount = false;
            $discountPercent = 0.0;

            if ($resolved !== null) {
                $hasDiscount = true;
                if ($resolved->fixedPrice !== null) {
                    $discountedPrice = min($effectivePrice, $resolved->fixedPrice);
                } elseif ($resolved->percent > 0) {
                    $discountedPrice = round($effectivePrice * (1 - $resolved->percent / 100), 2);
                    $discountPercent = $resolved->percent;
                }
                // Calculate percent from prices if fixed price was used
                if ($resolved->fixedPrice !== null && $effectivePrice > 0 && $discountedPrice < $effectivePrice) {
                    $discountPercent = round(($effectivePrice - $discountedPrice) / $effectivePrice * 100, 2);
                }
            }

            $formattedProducts[] = [
                '@id' => '/api/v1/products/' . $product->getId(),
                '@type' => 'Product',
                'id' => $product->getId(),
                'name' => $product->getName(),
                'slug' => $product->getSlug(),
                'partNo' => $product->getPartNo(),
                'shortDescription' => $product->getShortDescription(),
                'technicalDescription' => $product->getTechnicalDescription(),
                'statistic' => $product->getStatistic(),
                'machineText' => $product->getMachineText(),
                'unit' => $product->getUnit(),
                'weight' => $product->getWeight(),
                'regularPrice' => $product->getPrice(),
                'clientPrice' => $clientProductPrice->getPrice(),
                'discountPercentage' => $clientProductPrice->getDiscountPercentage(),
                'effectivePrice' => $effectivePrice,
                'discountedPrice' => round($discountedPrice, 2),
                'discountPercent' => $discountPercent,
                'hasDiscount' => $hasDiscount,
                'isValid' => $clientProductPrice->isValid(),
                'validFrom' => $clientProductPrice->getValidFrom()?->format('Y-m-d\TH:i:sP'),
                'validUntil' => $clientProductPrice->getValidUntil()?->format('Y-m-d\TH:i:sP'),
                'featuredImage' => $this->formatFeaturedImage($product),
                'imageGallery' => $this->formatImageGallery($product),
            ];
        }

        return $formattedProducts;
    }

    private function formatFeaturedImage(object $product): ?array
    {
        if (!$product->getFeaturedImage()) {
            return null;
        }

        $image = $product->getFeaturedImage();
        return [
            '@id' => '/api/v1/media_items/' . $image->getId(),
            '@type' => 'MediaItem',
            'id' => $image->getId(),
            'filename' => $image->getFilename(),
            'mimeType' => $image->getMimeType(),
            'filePath' => $image->getFilePath(),
        ];
    }

    private function formatImageGallery(object $product): array
    {
        if (!method_exists($product, 'getImageGallery') || !$product->getImageGallery()) {
            return [];
        }

        $gallery = [];
        foreach ($product->getImageGallery() as $image) {
            $gallery[] = [
                '@id' => '/api/v1/media_items/' . $image->getId(),
                '@type' => 'MediaItem',
                'id' => $image->getId(),
                'filename' => $image->getFilename(),
                'mimeType' => $image->getMimeType(),
                'filePath' => $image->getFilePath(),
            ];
        }

        return $gallery;
    }

    private function buildResponse(string $clientId, array $pagedProducts, int $totalItems, array $filters): array
    {
        $response = [
            '@context' => '/api/v1/contexts/ClientProduct',
            '@id' => '/api/v1/client/' . $clientId . '/products',
            '@type' => 'hydra:Collection',
            'totalItems' => $totalItems,
            'member' => $pagedProducts,
        ];

        return $response;
    }
    private function checkPermissions(): void
    {
        // Client-product pricing is master data: admin-only, matching security.yaml.
        // (Removed a reference to a non-existent ROLE_CLIENT_MANAGER role.)
        if (!$this->isGranted('ROLE_ADMIN')) {
            throw $this->createAccessDeniedException('Insufficient permissions.');
        }
    }

    /**
     * Non-admins may only access their own client's data. Prevents reading
     * another client's products/prices by changing the {clientId} in the URL.
     */
    private function assertClientAccess(string $clientId): void
    {
        if ($this->isGranted('ROLE_ADMIN')) {
            return;
        }
        $user = $this->getUser();
        $ownClientId = ($user && method_exists($user, 'getClient') && $user->getClient())
            ? (string) $user->getClient()->getId()
            : null;
        if ($ownClientId === null || $ownClientId !== (string) $clientId) {
            throw $this->createAccessDeniedException("You cannot access another client's products.");
        }
    }

    private function validatePriceData(Request $request): array
    {
        $data = json_decode($request->getContent(), true);

        if (!isset($data['price']) || !is_numeric($data['price']) || $data['price'] <= 0) {
            throw new \RuntimeException('Valid price is required');
        }

        return $data;
    }

    private function createOrUpdateClientProductPrice(object $client, object $product, array $data): object
    {
        $existingPrice = $this->clientProductPriceRepository->findOneBy([
            'client' => $client,
            'product' => $product
        ]);

        if ($existingPrice) {
            $this->updateClientProductPrice($existingPrice, $data);
            return $existingPrice;
        }

        return $this->createNewClientProductPrice($client, $product, $data);
    }

    private function updateClientProductPrice(object $clientProductPrice, array $data): void
    {
        $clientProductPrice->setPrice($data['price']);

        if (isset($data['discountPercentage'])) {
            $clientProductPrice->setDiscountPercentage($data['discountPercentage']);
        }

        if (isset($data['validFrom'])) {
            $clientProductPrice->setValidFrom(new \DateTime($data['validFrom']));
        }

        if (isset($data['validUntil'])) {
            $clientProductPrice->setValidUntil(new \DateTime($data['validUntil']));
        }

        $this->entityManager->flush();
    }

    private function createNewClientProductPrice(object $client, object $product, array $data): object
    {
        $clientProductPrice = new \App\Entity\ClientProductPrice();
        $clientProductPrice->setClient($client);
        $clientProductPrice->setProduct($product);
        $clientProductPrice->setPrice($data['price']);

        if (isset($data['discountPercentage'])) {
            $clientProductPrice->setDiscountPercentage($data['discountPercentage']);
        }

        if (isset($data['validFrom'])) {
            $clientProductPrice->setValidFrom(new \DateTime($data['validFrom']));
        }

        if (isset($data['validUntil'])) {
            $clientProductPrice->setValidUntil(new \DateTime($data['validUntil']));
        }

        $this->entityManager->persist($clientProductPrice);
        $this->entityManager->flush();

        return $clientProductPrice;
    }

    private function formatClientProductPriceResponse(object $clientProductPrice): array
    {
        return [
            '@context' => '/api/v1/contexts/ClientProductPrice',
            '@id' => '/api/v1/client_product_prices/' . $clientProductPrice->getId(),
            '@type' => 'ClientProductPrice',
            'id' => $clientProductPrice->getId(),
            'client' => '/api/v1/clients/' . $clientProductPrice->getClient()->getId(),
            'product' => '/api/v1/products/' . $clientProductPrice->getProduct()->getId(),
            'price' => $clientProductPrice->getPrice(),
            'discountPercentage' => $clientProductPrice->getDiscountPercentage(),
            'validFrom' => $clientProductPrice->getValidFrom()?->format('Y-m-d\TH:i:sP'),
            'validUntil' => $clientProductPrice->getValidUntil()?->format('Y-m-d\TH:i:sP')
        ];
    }

    private function generateDebugInfo(object $client): array
    {
        $clientPrices = $this->clientProductPriceRepository->findBy(['client' => $client], [], 3);
        $em = $this->entityManager;

        $debugInfo = [
            'client_prices_sample' => [],
            'mapping_debug' => []
        ];

        foreach ($clientPrices as $price) {
            $product = $price->getProduct();
            $debugInfo['client_prices_sample'][] = [
                'price_id' => $price->getId(),
                'product_is_null' => $product === null,
                'product_id' => $product?->getId(),
                'product_name' => $product?->getName(),
            ];
        }

        // Add various DQL tests...
        $this->addDqlTests($debugInfo, $em, $client);

        return $debugInfo;
    }

    private function addDqlTests(array &$debugInfo, $em, object $client): void
    {
        // Test 1: Simple DQL without explicit JOIN
        try {
            $dql1 = "SELECT cpp FROM App\Entity\ClientProductPrice cpp WHERE cpp.client = :client";
            $query1 = $em->createQuery($dql1);
            $query1->setParameter('client', $client);
            $query1->setMaxResults(3);
            $results1 = $query1->getResult();

            $debugInfo['simple_dql_query'] = [
                'success' => true,
                'count' => count($results1),
                'can_access_product' => $results1 && $results1[0]->getProduct() !== null
            ];
        } catch (\Exception $e) {
            $debugInfo['simple_dql_query'] = [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }

        // Add other DQL tests as needed...
    }
}
