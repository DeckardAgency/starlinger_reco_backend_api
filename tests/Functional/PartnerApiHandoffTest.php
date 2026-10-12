<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\Order;
use App\Entity\Product;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * What the partner handoff relies on (consistent with the inquiry tool):
 *  - the API documentation is public (Swagger UI, ReDoc, OpenAPI JSON) while the
 *    endpoints themselves still require authentication
 *  - orders and parts carry a `deepLink` into the webshop, documented in the schema
 *  - GET /products/random returns only active parts in stock, honouring `limit`
 *
 * Follows the TenantIsolationApiTest pattern (own fixtures per run, cleaned up).
 */
final class PartnerApiHandoffTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;
    private User $customer;
    private Order $order;
    private Product $partInStock;
    private Product $partOutOfStock;
    private Product $partInactive;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = bin2hex(random_bytes(4));

        $company = (new Client())
            ->setName('Handoff Test ' . $this->suffix)
            ->setCode('H-' . $this->suffix);
        $this->em->persist($company);

        $this->customer = (new User())
            ->setEmail('handoff-' . $this->suffix)
            ->setFirstName('Handoff')
            ->setLastName('Test');
        $this->customer->setPassword('not-used-in-tests');
        $this->customer->setRoles(['ROLE_CLIENT']);
        $this->customer->setClient($company);
        $this->em->persist($this->customer);

        $this->order = new Order();
        $this->order->setUser($this->customer);
        $this->order->setStatus(Order::STATUS_NEW);
        $this->order->setIsDraft(false);
        $this->order->setTotalAmount(10.0);
        $this->em->persist($this->order);

        $this->partInStock = $this->makeProduct('in', true, true);
        $this->partOutOfStock = $this->makeProduct('out', false, true);
        $this->partInactive = $this->makeProduct('inactive', true, false);

        $this->em->flush();
        $this->em->clear();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->em) && $this->em->isOpen()) {
                $this->em->createQuery('DELETE App\Entity\Order o WHERE o.user IN (SELECT u.id FROM App\Entity\User u WHERE u.email LIKE :s)')
                    ->setParameter('s', '%-' . $this->suffix)->execute();
                $this->em->createQuery('DELETE App\Entity\Product p WHERE p.partNo LIKE :s')
                    ->setParameter('s', 'HO-%-' . $this->suffix)->execute();
                $this->em->createQuery('DELETE App\Entity\User u WHERE u.email LIKE :s')
                    ->setParameter('s', '%-' . $this->suffix)->execute();
                $this->em->createQuery('DELETE App\Entity\Client c WHERE c.code LIKE :s')
                    ->setParameter('s', '%-' . $this->suffix)->execute();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testDocumentationIsPublicButEndpointsAreNot(): void
    {
        // Browsers send Accept: text/html; without it API Platform serves the JSON-LD (Hydra) docs.
        $this->client->request('GET', '/api/v1/docs', [], [], ['HTTP_ACCEPT' => 'text/html']);
        self::assertResponseIsSuccessful('Swagger UI');
        self::assertStringContainsString('swagger', strtolower((string) $this->client->getResponse()->getContent()));

        $this->client->request('GET', '/api/v1/docs?ui=re_doc', [], [], ['HTTP_ACCEPT' => 'text/html']);
        self::assertResponseIsSuccessful('ReDoc');
        self::assertStringContainsString('redoc', strtolower((string) $this->client->getResponse()->getContent()));

        $this->client->request('GET', '/api/v1/docs.jsonopenapi');
        self::assertResponseIsSuccessful('OpenAPI JSON');
        $spec = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('/api/v1/orders', $spec['paths']);
        self::assertArrayHasKey('/api/v1/products', $spec['paths']);
        self::assertArrayHasKey('/api/v1/products/random', $spec['paths']);
        self::assertStringContainsString('Parts in stock', $spec['info']['description']);

        $orderSchemas = array_filter(array_keys($spec['components']['schemas']), static fn (string $n) => str_starts_with($n, 'Order') && str_contains($n, 'order.read'));
        self::assertNotEmpty($orderSchemas);
        self::assertArrayHasKey('deepLink', $spec['components']['schemas'][reset($orderSchemas)]['properties'], 'deepLink is documented');

        $this->client->request('GET', '/api/v1/orders');
        self::assertResponseStatusCodeSame(401, 'endpoints stay authenticated');
        $this->client->request('GET', '/api/v1/products/random');
        self::assertResponseStatusCodeSame(401);
    }

    public function testOrdersAndPartsCarryDeepLinks(): void
    {
        $orders = $this->get('/api/v1/orders?isDraft=false');
        $mine = array_values(array_filter($orders['member'], fn (array $o) => $o['id'] === $this->order->getId()));
        self::assertCount(1, $mine, 'customer sees own order');
        self::assertStringEndsWith('/customer/orders/' . $this->order->getId(), $mine[0]['deepLink']);
        // Null-valued fields (e.g. customerReference, erpReference on this fixture) are omitted by API Platform's default serialization.
        foreach (['orderNumber', 'status', 'createdAt', 'itemsCount', 'totalQuantity', 'totalAmount', 'currency'] as $field) {
            self::assertArrayHasKey($field, $mine[0], "field $field");
        }

        $part = $this->get('/api/v1/products/' . $this->partInStock->getId());
        self::assertStringEndsWith('/customer/shop/products/' . $this->partInStock->getId(), $part['deepLink']);
        self::assertTrue($part['inStock']);
    }

    public function testPartsInStockFilterAndRandomSelection(): void
    {
        $inStock = $this->get('/api/v1/products?inStock=true&search=' . urlencode('-' . $this->suffix));
        self::assertSame([$this->partInStock->getId(), $this->partInactive->getId()], $this->ids($inStock), '/products does not hide inactive parts; inStock filters stock only');

        $random = $this->get('/api/v1/products/random?limit=2');
        self::assertLessThanOrEqual(2, \count($random['member']));
        self::assertGreaterThan(0, \count($random['member']));
        foreach ($random['member'] as $p) {
            self::assertTrue($p['inStock'], 'random parts are in stock');
            self::assertNotSame($this->partOutOfStock->getId(), $p['id']);
            self::assertNotSame($this->partInactive->getId(), $p['id'], 'random parts are active');
        }

        $this->get('/api/v1/products/random?limit=999'); // clamped, not an error
        self::assertResponseIsSuccessful();
    }

    // --- helpers ---------------------------------------------------------

    /** @return array<string, mixed> */
    private function get(string $url): array
    {
        $this->client->request('GET', $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->customer),
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        self::assertResponseIsSuccessful($url);

        return json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return list<int> */
    private function ids(array $collection): array
    {
        $ids = array_map(static fn (array $m): int => (int) $m['id'], $collection['member']);
        sort($ids);

        return $ids;
    }

    private function makeProduct(string $tag, bool $inStock, bool $active): Product
    {
        $product = new Product();
        $product->setName('Handoff test part ' . $tag . ' ' . $this->suffix);
        $product->setPartNo('HO-' . strtoupper($tag) . '-' . $this->suffix);
        $product->setPrice(9.5);
        $product->setInStock($inStock);
        $product->setIsActive($active);
        $this->em->persist($product);

        return $product;
    }
}
