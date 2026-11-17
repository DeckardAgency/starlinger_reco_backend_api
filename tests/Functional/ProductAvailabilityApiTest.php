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
 * Product availability (Nov 2026):
 *  - Active = off → hidden from customers (collection and item), still visible to admins;
 *  - In stock = off → visible, but an order containing it is refused at submit
 *    (drafts may still hold it; admins may order anyway).
 */
final class ProductAvailabilityApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;

    private User $customer;
    private User $admin;
    private Product $available;
    private Product $inactive;
    private Product $outOfStock;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = bin2hex(random_bytes(4));

        $company = (new Client())->setName('Avail Test ' . $this->suffix)->setCode('AV-' . $this->suffix);
        $this->em->persist($company);

        $this->customer = $this->makeUser('customer', ['ROLE_CLIENT'], $company);
        $this->admin = $this->makeUser('admin', ['ROLE_ADMIN'], null);

        $this->available = $this->makeProduct('available', true, true);
        $this->inactive = $this->makeProduct('inactive', false, true);
        $this->outOfStock = $this->makeProduct('outofstock', true, false);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->em) && $this->em->isOpen()) {
                $conn = $this->em->getConnection();
                $like = '%-' . $this->suffix;
                $conn->executeStatement(
                    'DELETE oi FROM order_item oi JOIN `order` o ON oi.order_ref_id = o.id
                     JOIN user u ON o.user_id = u.id WHERE u.email LIKE :s',
                    ['s' => $like]
                );
                $conn->executeStatement('DELETE o FROM `order` o JOIN user u ON o.user_id = u.id WHERE u.email LIKE :s', ['s' => $like]);
                $conn->executeStatement('DELETE FROM user WHERE email LIKE :s', ['s' => $like]);
                $conn->executeStatement('DELETE FROM product WHERE slug LIKE :s', ['s' => $like]);
                $conn->executeStatement('DELETE FROM client WHERE code LIKE :s', ['s' => $like]);
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testInactiveProductsAreHiddenFromCustomersOnly(): void
    {
        $list = $this->request('GET', '/api/v1/products?partNo=' . $this->suffix . '&itemsPerPage=50', $this->customer);
        self::assertSame(200, $list['status']);
        $ids = array_map(static fn (array $p): int => (int) $p['id'], $list['body']['member'] ?? []);
        self::assertContains($this->available->getId(), $ids);
        self::assertContains($this->outOfStock->getId(), $ids, 'Out of stock stays visible.');
        self::assertNotContains($this->inactive->getId(), $ids, 'Inactive is hidden from the shop.');

        self::assertSame(404, $this->request('GET', '/api/v1/products/' . $this->inactive->getId(), $this->customer)['status']);
        self::assertSame(200, $this->request('GET', '/api/v1/products/' . $this->outOfStock->getId(), $this->customer)['status']);

        $adminList = $this->request('GET', '/api/v1/products?partNo=' . $this->suffix . '&itemsPerPage=50', $this->admin);
        $adminIds = array_map(static fn (array $p): int => (int) $p['id'], $adminList['body']['member'] ?? []);
        self::assertContains($this->inactive->getId(), $adminIds, 'Admins keep seeing inactive products.');
        self::assertSame(200, $this->request('GET', '/api/v1/products/' . $this->inactive->getId(), $this->admin)['status']);
    }

    public function testOutOfStockProductCannotBeOrdered(): void
    {
        $r = $this->order($this->customer, $this->outOfStock, false);
        self::assertSame(400, $r['status'], 'Body: ' . json_encode($r['body']));
        self::assertStringContainsString('currently not available', $r['body']['detail'] ?? $r['body']['hydra:description'] ?? '');

        // A draft may still hold it (the shop blocks the checkout, not the saving).
        $r = $this->order($this->customer, $this->outOfStock, true);
        self::assertSame(201, $r['status'], 'Body: ' . json_encode($r['body']));

        // Available products order as before; admins are exempt from the rule.
        self::assertSame(201, $this->order($this->customer, $this->available, false)['status']);
        self::assertSame(201, $this->order($this->admin, $this->outOfStock, false)['status']);
    }

    public function testInactiveProductCannotBeOrdered(): void
    {
        // The IRI does not even resolve for a customer (hidden) → 400 from the denormalizer.
        $r = $this->order($this->customer, $this->inactive, false);
        self::assertContains($r['status'], [400, 422], 'Body: ' . json_encode($r['body']));
    }

    // --- helpers ------------------------------------------------------------

    /** @return array{status:int, body:array<mixed>} */
    private function order(User $as, Product $product, bool $draft): array
    {
        return $this->request('POST', '/api/v1/orders', $as, [
            'isDraft' => $draft,
            'items' => [['product' => '/api/v1/products/' . $product->getId(), 'quantity' => 1]],
        ]);
    }

    private function makeProduct(string $tag, bool $active, bool $inStock): Product
    {
        $p = (new Product())
            ->setName(ucfirst($tag) . ' ' . $this->suffix)
            ->setSlug($tag . '-' . $this->suffix)
            ->setPartNo($tag . '-' . $this->suffix)
            ->setPrice(10.0)
            ->setIsActive($active)
            ->setInStock($inStock);
        $this->em->persist($p);

        return $p;
    }

    /** @param string[] $roles */
    private function makeUser(string $tag, array $roles, ?Client $client): User
    {
        $u = (new User())
            ->setEmail($tag . '-' . $this->suffix)
            ->setFirstName('Test')->setLastName(ucfirst($tag));
        $u->setPassword('not-used');
        $u->setRoles($roles);
        if ($client !== null) {
            $u->setClient($client);
        }
        $this->em->persist($u);

        return $u;
    }

    /** @return array{status:int, body:array<mixed>} */
    private function request(string $method, string $url, User $as, ?array $json = null): array
    {
        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($as),
            'HTTP_ACCEPT' => 'application/ld+json',
        ];
        $content = null;
        if ($json !== null) {
            $server['CONTENT_TYPE'] = 'application/ld+json';
            $content = json_encode($json, JSON_THROW_ON_ERROR);
        }
        $this->client->request($method, $url, [], [], $server, $content);
        $response = $this->client->getResponse();
        $body = json_decode($response->getContent() ?: 'null', true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
    }
}
