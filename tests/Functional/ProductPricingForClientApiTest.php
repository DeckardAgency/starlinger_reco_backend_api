<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Address;
use App\Entity\Client;
use App\Entity\Discount;
use App\Entity\Product;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `?forClient=<id>` on product reads (Oct 2026): a client agent (or an admin)
 * sees the catalogue priced as a managed client would, so the shop shows the
 * same price the order is later created at. Covers the pricing, the
 * authorisation matrix (403/404/400), the OpenAPI documentation and the
 * shop-price = checkout-price consistency.
 *
 * Modelled on DiscountPricingApiTest / AgentCheckoutApiTest: WebTestCase, JWTs
 * minted via the token manager, own fixtures with a per-run suffix, cleaned up
 * in tearDown.
 */
final class ProductPricingForClientApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;

    /** Agent company, no discount of its own. */
    private Client $agentClient;
    /** Managed, +20 % markup. */
    private Client $markupClient;
    /** Managed, −20 % discount. */
    private Client $discountClient;
    /** NOT managed by the agent, −50 %. */
    private Client $foreignClient;
    /** Ordinary company, no discount. */
    private Client $plainClient;

    private User $agentUser;
    private User $plainUser;
    private User $admin;

    private Product $p1;
    private Product $p2;

    private Address $markupDelivery;
    private Address $discountDelivery;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->suffix = bin2hex(random_bytes(4));

        $this->agentClient = $this->makeClient('AGENT')->setIsClientAgent(true);
        $this->markupClient = $this->makeClient('MARK');
        $this->discountClient = $this->makeClient('DISC');
        $this->foreignClient = $this->makeClient('FOREIGN');
        $this->plainClient = $this->makeClient('PLAIN');
        $this->agentClient->addManagedClient($this->markupClient);
        $this->agentClient->addManagedClient($this->discountClient);

        $this->markupDelivery = $this->makeDeliveryAddress($this->markupClient, 'Markup Road 1', 'Marktown');
        $this->discountDelivery = $this->makeDeliveryAddress($this->discountClient, 'Discount Lane 2', 'Discville');

        $this->agentUser = $this->makeUser('agent', ['ROLE_USER_CLIENT_AGENT', 'ROLE_CLIENT'], $this->agentClient);
        $this->plainUser = $this->makeUser('plain', ['ROLE_CLIENT'], $this->plainClient);
        $this->admin = $this->makeUser('admin', ['ROLE_ADMIN'], null);

        $this->p1 = $this->makeProduct('p1');
        $this->p2 = $this->makeProduct('p2');

        $this->makeDiscount('markup +20', '20.0000', $this->markupClient);
        $this->makeDiscount('discount -20', '-20.0000', $this->discountClient);
        $this->makeDiscount('foreign -50', '-50.0000', $this->foreignClient);

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
                $conn->executeStatement(
                    'DELETE o FROM `order` o JOIN user u ON o.user_id = u.id WHERE u.email LIKE :s',
                    ['s' => $like]
                );
                $conn->executeStatement('DELETE FROM user WHERE email LIKE :s', ['s' => $like]);
                $conn->executeStatement(
                    'DELETE dc FROM discount_client dc JOIN discount d ON dc.discount_id = d.id WHERE d.name LIKE :s',
                    ['s' => $like]
                );
                $conn->executeStatement('DELETE FROM discount WHERE name LIKE :s', ['s' => $like]);
                $conn->executeStatement('DELETE FROM product WHERE slug LIKE :s', ['s' => $like]);
                $conn->executeStatement(
                    'DELETE a FROM address a JOIN client c ON a.client_id = c.id WHERE c.code LIKE :s',
                    ['s' => $like]
                );
                $conn->executeStatement(
                    'DELETE cam FROM client_agent_managed_clients cam
                     JOIN client c ON (cam.agent_client_id = c.id OR cam.managed_client_id = c.id)
                     WHERE c.code LIKE :s',
                    ['s' => $like]
                );
                $conn->executeStatement('DELETE FROM client WHERE code LIKE :s', ['s' => $like]);
            }
        } finally {
            parent::tearDown();
        }
    }

    // --- 1. item: markup --------------------------------------------------------

    public function testAgentSeesTheManagedClientsMarkupOnlyWithForClient(): void
    {
        $forMarkup = $this->getProduct($this->p1, $this->agentUser, $this->markupClient->getId());
        self::assertSame(120.0, (float) $forMarkup['price'], 'The managed client\'s +20 % is simply its price.');
        self::assertFalse($forMarkup['hasDiscount']);
        self::assertSame(0.0, (float) $forMarkup['campaignDiscountPercent']);
        self::assertNull($forMarkup['discountedPrice']);

        $own = $this->getProduct($this->p1, $this->agentUser);
        self::assertSame(100.0, (float) $own['price'], 'Without the parameter the agent sees the catalogue price.');
        self::assertFalse($own['hasDiscount']);
    }

    // --- 2. collection: discount -------------------------------------------------

    public function testAgentCollectionIsPricedForTheManagedClientsDiscount(): void
    {
        $r = $this->request('GET', $this->collectionUrl($this->discountClient->getId()), $this->agentUser);
        self::assertSame(200, $r['status'], json_encode($r['body']));

        $rows = $r['body']['member'] ?? [];
        self::assertEqualsCanonicalizing([$this->p1->getId(), $this->p2->getId()], array_column($rows, 'id'));
        foreach ($rows as $row) {
            self::assertTrue($row['hasDiscount'], 'Product ' . $row['id']);
            self::assertSame(20.0, (float) $row['campaignDiscountPercent']);
            self::assertSame(100.0, (float) $row['price'], 'The base price stays the price.');
            self::assertSame(80.0, (float) $row['discountedPrice']);
            self::assertSame('campaign', $row['discountSource']);
        }

        // Same listing without the parameter: the agent's own (undiscounted) prices.
        $r = $this->request('GET', $this->collectionUrl(null), $this->agentUser);
        self::assertSame(200, $r['status']);
        foreach ($r['body']['member'] as $row) {
            self::assertFalse($row['hasDiscount']);
            self::assertSame(100.0, (float) $row['price']);
        }
    }

    // --- 3. authorisation ----------------------------------------------------------

    public function testAgentMayNotPriceForAClientItDoesNotManage(): void
    {
        $r = $this->request('GET', $this->itemUrl($this->p1, $this->foreignClient->getId()), $this->agentUser);
        self::assertSame(403, $r['status'], json_encode($r['body']));
        self::assertSame(
            sprintf('Your company does not manage client "%s".', $this->foreignClient->getName()),
            $r['body']['detail'] ?? null
        );

        $r = $this->request('GET', $this->collectionUrl($this->foreignClient->getId()), $this->agentUser);
        self::assertSame(403, $r['status'], 'The collection is refused as well.');
        self::assertArrayNotHasKey('member', $r['body'], 'No products leak in the error body.');
    }

    public function testRegularUserMayNotPriceForAnotherClient(): void
    {
        $r = $this->request('GET', $this->itemUrl($this->p1, $this->discountClient->getId()), $this->plainUser);
        self::assertSame(403, $r['status'], json_encode($r['body']));
        self::assertSame('You are not allowed to act on behalf of other clients.', $r['body']['detail'] ?? null);
    }

    public function testNamingOnesOwnCompanyIsAlwaysAllowed(): void
    {
        $p = $this->getProduct($this->p1, $this->plainUser, $this->plainClient->getId());
        self::assertSame(100.0, (float) $p['price']);
        self::assertFalse($p['hasDiscount']);

        $p = $this->getProduct($this->p1, $this->agentUser, $this->agentClient->getId());
        self::assertSame(100.0, (float) $p['price'], 'An agent pricing for its own company = no parameter.');
    }

    public function testAdminMayPriceForAnyClient(): void
    {
        $markup = $this->getProduct($this->p1, $this->admin, $this->markupClient->getId());
        self::assertSame(120.0, (float) $markup['price']);
        self::assertFalse($markup['hasDiscount']);

        $discount = $this->getProduct($this->p1, $this->admin, $this->discountClient->getId());
        self::assertTrue($discount['hasDiscount']);
        self::assertSame(80.0, (float) $discount['discountedPrice']);

        $foreign = $this->getProduct($this->p1, $this->admin, $this->foreignClient->getId());
        self::assertSame(50.0, (float) $foreign['discountedPrice'], 'Admins are not limited to managed clients.');

        $none = $this->getProduct($this->p1, $this->admin);
        self::assertSame(100.0, (float) $none['price']);
        self::assertFalse($none['hasDiscount'], 'Without the parameter an admin (no company) sees the catalogue price.');
    }

    #[DataProvider('malformedValues')]
    public function testMalformedForClientIsABadRequest(string $value): void
    {
        $r = $this->request('GET', '/api/v1/products/' . $this->p1->getId() . '?forClient=' . urlencode($value), $this->agentUser);
        self::assertSame(400, $r['status'], json_encode($r['body']));
        self::assertSame('"forClient" must be a positive integer client id.', $r['body']['detail'] ?? null);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedValues(): iterable
    {
        yield 'letters' => ['abc'];
        yield 'zero' => ['0'];
        yield 'negative' => ['-1'];
        yield 'decimal' => ['1.5'];
        yield 'trailing garbage' => ['12abc'];
    }

    public function testUnknownClientIsNotFound(): void
    {
        $r = $this->request('GET', $this->itemUrl($this->p1, 999999), $this->admin);
        self::assertSame(404, $r['status'], json_encode($r['body']));
        self::assertSame('Client not found.', $r['body']['detail'] ?? null);

        $r = $this->request('GET', $this->itemUrl($this->p1, 999999), $this->agentUser);
        self::assertSame(404, $r['status'], 'Same for an agent.');
    }

    public function testEmptyForClientMeansTheCallersOwnCompany(): void
    {
        $r = $this->request('GET', '/api/v1/products/' . $this->p1->getId() . '?forClient=', $this->agentUser);
        self::assertSame(200, $r['status'], json_encode($r['body']));
        self::assertSame(100.0, (float) $r['body']['price']);
    }

    // --- 4. shop price == order price -------------------------------------------------

    public function testCheckoutUnitPriceEqualsTheForClientShopPrice(): void
    {
        $shopMarkup = $this->getProduct($this->p1, $this->agentUser, $this->markupClient->getId());
        $shopDiscount = $this->getProduct($this->p1, $this->agentUser, $this->discountClient->getId());

        $r = $this->request('POST', '/api/v1/orders/checkout', $this->agentUser, [
            'isDraft' => false,
            'groups' => [
                [
                    'onBehalfOfClient' => '/api/v1/clients/' . $this->markupClient->getId(),
                    'shippingAddressId' => $this->markupDelivery->getId(),
                    'billingAddressId' => null,
                    'items' => [['product' => '/api/v1/products/' . $this->p1->getId(), 'quantity' => 2]],
                ],
                [
                    'onBehalfOfClient' => '/api/v1/clients/' . $this->discountClient->getId(),
                    'shippingAddressId' => $this->discountDelivery->getId(),
                    'billingAddressId' => null,
                    'items' => [['product' => '/api/v1/products/' . $this->p1->getId(), 'quantity' => 3]],
                ],
            ],
        ], 'application/json');
        self::assertSame(201, $r['status'], json_encode($r['body']));
        self::assertCount(2, $r['body']['orders']);

        $byClient = [];
        foreach ($r['body']['orders'] as $order) {
            $byClient[$order['onBehalfOfClient']['id']] = $order;
        }

        $markupItem = $byClient[$this->markupClient->getId()]['items'][0];
        self::assertSame((float) $shopMarkup['price'], (float) $markupItem['unitPrice'], 'Markup: the shop price IS the order price.');
        self::assertSame(120.0, (float) $markupItem['unitPrice']);
        self::assertSame(240.0, (float) $byClient[$this->markupClient->getId()]['totalAmount']);

        $discountItem = $byClient[$this->discountClient->getId()]['items'][0];
        self::assertSame((float) $shopDiscount['discountedPrice'], (float) $discountItem['unitPrice'], 'Discount: the shop\'s discountedPrice IS the order price.');
        self::assertSame(80.0, (float) $discountItem['unitPrice']);
        self::assertSame((float) $shopDiscount['price'], (float) $discountItem['originalUnitPrice']);
        self::assertSame(240.0, (float) $byClient[$this->discountClient->getId()]['totalAmount']);
    }

    // --- 5. documented ------------------------------------------------------------------

    public function testForClientIsDocumentedOnTheProductReadOperations(): void
    {
        $this->client->request('GET', '/api/v1/docs.jsonopenapi');
        self::assertResponseIsSuccessful();
        $spec = json_decode((string) $this->client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        foreach (['/api/v1/products', '/api/v1/products/{id}', '/api/v1/products/random'] as $path) {
            $names = array_column($spec['paths'][$path]['get']['parameters'] ?? [], 'name');
            self::assertContains('forClient', $names, $path . ' documents forClient');
        }
        // The collection keeps its generated filter/pagination parameters next to it.
        $collectionParams = array_column($spec['paths']['/api/v1/products']['get']['parameters'], 'name');
        self::assertContains('page', $collectionParams);
        self::assertContains('search', $collectionParams);
        self::assertContains('inStock', $collectionParams);
    }

    // --- helpers ------------------------------------------------------------

    private function itemUrl(Product $product, ?int $forClient): string
    {
        return '/api/v1/products/' . $product->getId() . ($forClient !== null ? '?forClient=' . $forClient : '');
    }

    private function collectionUrl(?int $forClient): string
    {
        return '/api/v1/products?search=' . $this->suffix . '&itemsPerPage=50' . ($forClient !== null ? '&forClient=' . $forClient : '');
    }

    /** @return array<string,mixed> */
    private function getProduct(Product $product, User $as, ?int $forClient = null): array
    {
        $r = $this->request('GET', $this->itemUrl($product, $forClient), $as);
        self::assertSame(200, $r['status'], json_encode($r['body']));

        return $r['body'];
    }

    private function makeClient(string $tag): Client
    {
        $c = (new Client())->setName('Client ' . $tag . ' ' . $this->suffix)->setCode($tag . '-' . $this->suffix);
        $this->em->persist($c);

        return $c;
    }

    private function makeDeliveryAddress(Client $client, string $street, string $city): Address
    {
        $a = (new Address())
            ->setClient($client)
            ->setStreet($street)
            ->setCity($city)
            ->setPostalCode('1000')
            ->setIsDelivery(true)
            ->setIsBilling(false);
        $this->em->persist($a);

        return $a;
    }

    /** @param string[] $roles */
    private function makeUser(string $tag, array $roles, ?Client $client): User
    {
        $u = (new User())
            ->setEmail($tag . '-' . $this->suffix)
            ->setFirstName('Test')->setLastName(ucfirst($tag));
        $u->setPassword('not-used');
        $u->setRoles($roles);
        $u->setClient($client);
        $this->em->persist($u);

        return $u;
    }

    private function makeProduct(string $tag): Product
    {
        $p = (new Product())
            ->setName('ForClient ' . $tag . ' ' . $this->suffix)
            ->setSlug('forclient-' . $tag . '-' . $this->suffix)
            ->setPartNo('FC-' . strtoupper($tag) . '-' . $this->suffix)
            ->setPrice(100.0);
        $this->em->persist($p);

        return $p;
    }

    private function makeDiscount(string $name, string $adjustmentPercent, Client $client): Discount
    {
        $d = (new Discount())
            ->setName($name . ' -' . $this->suffix)
            ->setAdjustmentPercent($adjustmentPercent)
            ->addClient($client);
        $this->em->persist($d);

        return $d;
    }

    /** @return array{status:int, body:array<mixed>} */
    private function request(string $method, string $url, User $as, ?array $json = null, string $contentType = 'application/ld+json'): array
    {
        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($as),
            'HTTP_ACCEPT' => 'application/ld+json',
        ];
        $content = null;
        if ($json !== null) {
            $server['CONTENT_TYPE'] = $contentType;
            $content = json_encode($json, JSON_THROW_ON_ERROR);
        }

        $this->client->request($method, $url, [], [], $server, $content);
        $response = $this->client->getResponse();
        $body = json_decode($response->getContent() ?: 'null', true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
    }
}
