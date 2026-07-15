<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\Product;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression coverage for the order-status injection fix (H1):
 *  - A customer cannot create an order in an arbitrary downstream status
 *    (e.g. "delivered") — the server forces new orders to draft/new, so the
 *    workflow, payment states and notifications can't be skipped.
 *  - A garbage status string is rejected by validation (Assert\Choice).
 *
 * Builds its own fixtures with a unique per-run suffix and cleans them up.
 */
final class OrderStatusInjectionApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;

    private Client $clientA;
    private User $customer;
    private Product $product;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->suffix = bin2hex(random_bytes(4));

        $this->clientA = (new Client())
            ->setName('Order Test ' . $this->suffix)
            ->setCode('OT-' . $this->suffix);
        $this->em->persist($this->clientA);

        $this->customer = (new User())
            ->setEmail('customer-' . $this->suffix)
            ->setFirstName('Test')
            ->setLastName('Customer');
        $this->customer->setPassword('not-used');
        $this->customer->setRoles(['ROLE_CLIENT']);
        $this->customer->setClient($this->clientA);
        $this->em->persist($this->customer);

        $this->product = (new Product())
            ->setName('Test Product ' . $this->suffix)
            ->setSlug('test-product-' . $this->suffix)
            ->setPrice(9.99);
        $this->em->persist($this->product);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->em) && $this->em->isOpen()) {
                $conn = $this->em->getConnection();
                $like = '%-' . $this->suffix;
                // FK-safe order: order_item -> order -> user -> product -> client.
                $conn->executeStatement(
                    'DELETE oi FROM order_item oi
                     JOIN `order` o ON oi.order_ref_id = o.id
                     JOIN user u ON o.user_id = u.id
                     WHERE u.email LIKE :s',
                    ['s' => $like]
                );
                $conn->executeStatement(
                    'DELETE o FROM `order` o JOIN user u ON o.user_id = u.id WHERE u.email LIKE :s',
                    ['s' => $like]
                );
                $conn->executeStatement('DELETE FROM user WHERE email LIKE :s', ['s' => $like]);
                $conn->executeStatement('DELETE FROM product WHERE slug LIKE :s', ['s' => $like]);
                $conn->executeStatement('DELETE FROM client WHERE code LIKE :s', ['s' => $like]);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testCustomerCannotCreateOrderInArbitraryStatus(): void
    {
        $response = $this->post('/api/v1/orders', [
            'isDraft' => true,
            'status' => 'delivered', // valid enum value, but a customer must not set it on create
            'items' => [[
                'product' => '/api/v1/products/' . $this->product->getId(),
                'quantity' => 1,
            ]],
        ]);

        self::assertSame(201, $response['status'], 'Order create should succeed. Body: ' . json_encode($response['body']));
        $status = $response['body']['status'] ?? null;
        // The injected downstream status must be overridden to a safe create status.
        // (Sending an explicit status flips isDraft off, so it resolves to "new".)
        self::assertNotSame('delivered', $status, 'A customer must not be able to create a "delivered" order.');
        self::assertContains($status, ['draft', 'new'], 'Create status must be forced to draft/new.');
    }

    public function testInvalidStatusIsRejected(): void
    {
        $response = $this->post('/api/v1/orders', [
            'isDraft' => true,
            'status' => 'totally_not_a_status',
            'items' => [[
                'product' => '/api/v1/products/' . $this->product->getId(),
                'quantity' => 1,
            ]],
        ]);

        self::assertSame(422, $response['status'], 'A garbage status string must fail validation.');
    }

    /** @return array{status:int, body:array<mixed>} */
    private function post(string $url, array $json): array
    {
        $this->client->request('POST', $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()
                ->get(JWTTokenManagerInterface::class)->create($this->customer),
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode($json, JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();
        $body = json_decode($response->getContent() ?: 'null', true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
    }
}
