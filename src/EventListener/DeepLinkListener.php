<?php

namespace App\EventListener;

use App\Entity\Order;
use App\Entity\Product;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Events;

/**
 * Fills the transient `deepLink` of orders and products — the absolute webshop
 * URL of the item — from CLIENT_APP_URL whenever an entity is loaded or newly
 * persisted. Done here rather than in a serializer normalizer so the field is
 * a declared, documented property (visible in the OpenAPI schema).
 */
#[AsEntityListener(event: Events::postLoad, method: 'linkOrder', entity: Order::class)]
#[AsEntityListener(event: Events::postPersist, method: 'linkOrder', entity: Order::class)]
#[AsEntityListener(event: Events::postLoad, method: 'linkProduct', entity: Product::class)]
#[AsEntityListener(event: Events::postPersist, method: 'linkProduct', entity: Product::class)]
final class DeepLinkListener
{
    public function __construct(private readonly string $clientAppUrl)
    {
    }

    public function linkOrder(Order $order): void
    {
        $order->setDeepLink($this->url('/customer/orders/' . $order->getId()));
    }

    public function linkProduct(Product $product): void
    {
        $product->setDeepLink($this->url('/customer/shop/products/' . $product->getId()));
    }

    private function url(string $path): string
    {
        return rtrim($this->clientAppUrl, '/') . $path;
    }
}
