<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\Product;
use App\Entity\Order;
use App\Entity\ClientProductPrice;
use App\Repository\ClientProductPriceRepository;
use Symfony\Contracts\Service\ResetInterface;

class PriceCalculator implements ResetInterface
{
    /**
     * Request-scoped prefetch cache: clientId => productId => ClientProductPrice|null.
     * `null` means "prefetched, no custom price exists" so we don't re-query misses.
     *
     * @var array<int, array<int, ClientProductPrice|null>>
     */
    private array $prefetchedPrices = [];

    public function __construct(
        private ClientProductPriceRepository $clientProductPriceRepository
    ) {}

    /**
     * Clear the prefetch cache (called by the container between requests and
     * between Messenger messages, since workers are long-lived).
     */
    public function reset(): void
    {
        $this->prefetchedPrices = [];
    }

    /**
     * Prefetch custom prices for a client and a set of products with a single
     * query, so subsequent getClientProductPrice() calls hit the in-memory map
     * instead of issuing one query per product.
     *
     * @param Product[] $products
     */
    public function prefetchClientPrices(Client $client, array $products): void
    {
        $clientId = $client->getId();
        if ($clientId === null) {
            return;
        }

        $missingIds = [];
        foreach ($products as $product) {
            if (!$product instanceof Product) {
                continue;
            }
            $productId = $product->getId();
            if ($productId === null || \array_key_exists($productId, $this->prefetchedPrices[$clientId] ?? [])) {
                continue;
            }
            $missingIds[$productId] = true;
        }

        if ($missingIds === []) {
            return;
        }

        // Pre-mark all requested products as misses; found rows overwrite below.
        foreach (array_keys($missingIds) as $productId) {
            $this->prefetchedPrices[$clientId][$productId] = null;
        }

        $rows = $this->clientProductPriceRepository->findBy([
            'client' => $client,
            'product' => array_keys($missingIds),
        ]);

        foreach ($rows as $clientProductPrice) {
            $productId = $clientProductPrice->getProduct()?->getId();
            if ($productId !== null) {
                $this->prefetchedPrices[$clientId][$productId] = $clientProductPrice;
            }
        }
    }

    /**
     * Get client-specific price for a product.
     *
     * Consults the prefetch map first (see prefetchClientPrices()); falls back
     * to a targeted single-row lookup instead of scanning the client's whole
     * price collection (which hydrated every ClientProductPrice row per call).
     * (client, product) is unique (uniq_cpp_client_product), so at most one row exists.
     */
    public function getClientProductPrice(Client $client, Product $product): ?ClientProductPrice
    {
        $clientId = $client->getId();
        $productId = $product->getId();

        if ($clientId !== null && $productId !== null
            && \array_key_exists($clientId, $this->prefetchedPrices)
            && \array_key_exists($productId, $this->prefetchedPrices[$clientId])
        ) {
            $clientProductPrice = $this->prefetchedPrices[$clientId][$productId];
        } else {
            $clientProductPrice = $this->clientProductPriceRepository->findCustomPrice($client, $product);
        }

        if ($clientProductPrice !== null && $clientProductPrice->isValid()) {
            return $clientProductPrice;
        }

        return null;
    }

    /**
     * Prefetch custom prices for all products in an order (single query).
     */
    private function prefetchForOrder(?Client $client, Order $order): void
    {
        if (!$client) {
            return;
        }

        $products = [];
        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            if ($product !== null) {
                $products[] = $product;
            }
        }

        if ($products !== []) {
            $this->prefetchClientPrices($client, $products);
        }
    }

    /**
     * Calculate total order amount based on client pricing
     */
    public function calculateOrderTotal(Order $order): float
    {
        $totalAmount = 0;
        $user = $order->getUser();
        $client = $user ? $user->getClient() : null;
        $this->prefetchForOrder($client, $order);

        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            if (!$product) {
                $totalAmount += $item->getSubtotal();
                continue;
            }

            $unitPrice = $item->getUnitPrice();
            $quantity = $item->getQuantity();

            // If there's a client and the price hasn't been set yet, calculate it
            if ($client && $unitPrice === 0) {
                $clientProductPrice = $this->getClientProductPrice($client, $product);
                if ($clientProductPrice && $clientProductPrice->isValid()) {
                    $unitPrice = $clientProductPrice->getEffectivePrice();
                } else {
                    // Fall back to standard product price
                    $unitPrice = $product->getPrice();
                }
            }

            $totalAmount += ($unitPrice * $quantity);
        }

        return $totalAmount;
    }

    /**
     * Get detailed order items with proper pricing
     */
    public function getOrderItemsDetails(Order $order): array
    {
        $items = [];
        $user = $order->getUser();
        $client = $user ? $user->getClient() : null;
        $this->prefetchForOrder($client, $order);

        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            if (!$product) {
                $items[] = [
                    'name' => 'Unknown Product',
                    'quantity' => $item->getQuantity(),
                    'unitPrice' => $item->getUnitPrice(),
                    'subtotal' => $item->getSubtotal(),
                    'isCustomPrice' => false
                ];
                continue;
            }

            $unitPrice = $item->getUnitPrice();
            $isCustomPrice = $item->isCustomPrice();

            // If price is 0, recalculate
            if ($unitPrice === 0 && $client) {
                $clientProductPrice = $this->getClientProductPrice($client, $product);
                if ($clientProductPrice && $clientProductPrice->isValid()) {
                    $unitPrice = $clientProductPrice->getEffectivePrice();
                    $isCustomPrice = true;
                } else {
                    $unitPrice = $product->getPrice();
                    $isCustomPrice = false;
                }
            } elseif ($unitPrice === 0) {
                // No client, use standard price
                $unitPrice = $product->getPrice();
                $isCustomPrice = false;
            }

            $subtotal = $unitPrice * $item->getQuantity();

            $items[] = [
                'name' => $product->getName(),
                'quantity' => $item->getQuantity(),
                'unitPrice' => $unitPrice,
                'subtotal' => $subtotal,
                'isCustomPrice' => $isCustomPrice
            ];
        }

        return $items;
    }

    /**
     * Format item list for email display
     */
    public function formatItemListHtml(array $items, bool $showCustomPriceInfo = true): string
    {
        $itemsList = '';
        foreach ($items as $item) {
            $itemsList .= sprintf(
                '%s x %d @ %s%s = %s<br>',
                htmlspecialchars($item['name']),
                $item['quantity'],
                number_format($item['unitPrice'], 2),
                ($showCustomPriceInfo && $item['isCustomPrice']) ? ' (Custom Price)' : '',
                number_format($item['subtotal'], 2)
            );
        }

        return $itemsList;
    }
}
