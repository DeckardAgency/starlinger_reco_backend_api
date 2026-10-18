<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\Discount;
use App\Entity\Product;
use App\Entity\ProductDiscount;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * End-to-end pricing contract of discounts/markups (Oct 2026): a Discount is ONE
 * signed `adjustmentPercent` for a required list of clients. Negative = discount
 * (shown as old price, "-X%", new price), positive = markup (shown as a plain,
 * raised price). Covers what the shop sees on products, what an order snapshots,
 * the resolver's precedence rules and the admin write validation.
 *
 * Modelled on AgentCheckoutApiTest: WebTestCase, JWTs minted via the token
 * manager, own fixtures with a per-run suffix, cleaned up in tearDown.
 */
final class DiscountPricingApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;

    private User $admin;
    /** -20 % client-wide, plus product-specific links on P2 (−40), P3 (fixed 55) and P4 (rebate −25). */
    private User $discountUser;
    private Client $discountClient;
    /** +20 % client-wide. */
    private User $markupUser;
    /** Only an INACTIVE −50 % discount. */
    private User $plainUser;
    /** Two client-wide discounts: −10 (lower id) and −30. */
    private User $twoDiscountsUser;

    private Product $p1;
    private Product $p2;
    private Product $p3;
    private Product $p4;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->suffix = bin2hex(random_bytes(4));

        $this->discountClient = $this->makeClient('DISC');
        $markupClient = $this->makeClient('MARK');
        $plainClient = $this->makeClient('PLAIN');
        $twoClient = $this->makeClient('TWO');

        $this->admin = $this->makeUser('admin', ['ROLE_ADMIN'], null);
        $this->discountUser = $this->makeUser('disc', ['ROLE_CLIENT'], $this->discountClient);
        $this->markupUser = $this->makeUser('mark', ['ROLE_CLIENT'], $markupClient);
        $this->plainUser = $this->makeUser('plain', ['ROLE_CLIENT'], $plainClient);
        $this->twoDiscountsUser = $this->makeUser('two', ['ROLE_CLIENT'], $twoClient);

        $this->p1 = $this->makeProduct('p1');
        $this->p2 = $this->makeProduct('p2');
        $this->p3 = $this->makeProduct('p3');
        $this->p4 = $this->makeProduct('p4');

        $clientWide = $this->makeDiscount('wide -20', '-20.0000', [$this->discountClient]);
        $specific = $this->makeDiscount('specific -40', '-40.0000', [$this->discountClient]);
        $fixed = $this->makeDiscount('fixed 55', '-5.0000', [$this->discountClient]);
        $rebate = $this->makeDiscount('rebate link', '10.0000', [$this->discountClient]);
        $this->makeDiscount('markup +20', '20.0000', [$markupClient]);
        $this->makeDiscount('inactive -50', '-50.0000', [$plainClient])->setIsActive(false);
        $this->makeDiscount('two first -10', '-10.0000', [$twoClient]);
        $this->makeDiscount('two second -30', '-30.0000', [$twoClient]);
        $this->em->flush();

        $this->em->persist((new ProductDiscount())->setProductId($this->p2->getId())->setDiscountId($specific->getId()));
        $this->em->persist((new ProductDiscount())->setProductId($this->p3->getId())->setDiscountId($fixed->getId())->setDiscountPriceBase('55.0000'));
        $this->em->persist((new ProductDiscount())->setProductId($this->p4->getId())->setDiscountId($rebate->getId())->setRebate('-25.0000'));
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
                    'DELETE pd FROM product_discount pd JOIN discount d ON pd.discount_id = d.id WHERE d.name LIKE :s',
                    ['s' => $like]
                );
                $conn->executeStatement(
                    'DELETE dc FROM discount_client dc JOIN discount d ON dc.discount_id = d.id WHERE d.name LIKE :s',
                    ['s' => $like]
                );
                $conn->executeStatement('DELETE FROM discount WHERE name LIKE :s', ['s' => $like]);
                $conn->executeStatement('DELETE FROM product WHERE slug LIKE :s', ['s' => $like]);
                $conn->executeStatement('DELETE FROM client WHERE code LIKE :s', ['s' => $like]);
            }
        } finally {
            parent::tearDown();
        }
    }

    // --- what the shop sees ---------------------------------------------------

    public function testDiscountShowsBasePriceBadgeAndDiscountedPrice(): void
    {
        $p = $this->getProduct($this->p1, $this->discountUser);

        self::assertSame(100.0, (float) $p['price'], 'The base price stays the price.');
        self::assertTrue($p['hasDiscount']);
        self::assertSame(20.0, (float) $p['campaignDiscountPercent'], 'Positive number: the shop renders "-20%".');
        self::assertSame(80.0, (float) $p['discountedPrice']);
        self::assertSame('campaign', $p['discountSource']);
    }

    public function testMarkupIsShownAsAPlainRaisedPrice(): void
    {
        $p = $this->getProduct($this->p1, $this->markupUser);

        self::assertSame(120.0, (float) $p['price'], 'The raised price simply becomes the price.');
        self::assertFalse($p['hasDiscount']);
        self::assertSame(0.0, (float) $p['campaignDiscountPercent']);
        self::assertArrayHasKey('discountedPrice', $p);
        self::assertNull($p['discountedPrice']);
        self::assertNull($p['discountSource']);
    }

    public function testClientWithoutActiveDiscountSeesTheBasePrice(): void
    {
        $p = $this->getProduct($this->p1, $this->plainUser);

        self::assertSame(100.0, (float) $p['price']);
        self::assertFalse($p['hasDiscount'], 'The inactive -50 % discount is ignored.');
        self::assertSame(0.0, (float) $p['campaignDiscountPercent']);
        self::assertNull($p['discountedPrice']);
    }

    public function testAdminWithoutClientSeesTheBasePrice(): void
    {
        $p = $this->getProduct($this->p1, $this->admin);

        self::assertSame(100.0, (float) $p['price']);
        self::assertFalse($p['hasDiscount']);
    }

    // --- resolver precedence ------------------------------------------------------

    public function testProductSpecificLinkBeatsClientWideDiscount(): void
    {
        $p2 = $this->getProduct($this->p2, $this->discountUser);
        self::assertSame(60.0, (float) $p2['discountedPrice'], 'The -40 % linked to P2 wins over the client-wide -20 %.');
        self::assertSame(40.0, (float) $p2['campaignDiscountPercent']);

        $p1 = $this->getProduct($this->p1, $this->discountUser);
        self::assertSame(80.0, (float) $p1['discountedPrice'], 'Unlinked products keep the client-wide -20 %.');
    }

    public function testPerLinkRebateOverridesTheDiscountPercent(): void
    {
        $p4 = $this->getProduct($this->p4, $this->discountUser);

        self::assertTrue($p4['hasDiscount'], 'The link\'s -25 % rebate wins over the discount\'s own +10 %.');
        self::assertSame(75.0, (float) $p4['discountedPrice']);
        self::assertSame(25.0, (float) $p4['campaignDiscountPercent']);
        self::assertSame(100.0, (float) $p4['price']);
    }

    public function testFixedPriceOverrideIsADiscountWhenItLowersThePrice(): void
    {
        $p3 = $this->getProduct($this->p3, $this->discountUser);

        self::assertTrue($p3['hasDiscount']);
        self::assertSame(100.0, (float) $p3['price']);
        self::assertSame(55.0, (float) $p3['discountedPrice']);
        self::assertSame(45.0, (float) $p3['campaignDiscountPercent'], 'Derived from the prices.');
    }

    public function testLowestIdWinsAmongClientWideDiscounts(): void
    {
        $p = $this->getProduct($this->p1, $this->twoDiscountsUser);

        self::assertSame(90.0, (float) $p['discountedPrice'], 'The first (-10 %) discount wins, not the bigger -30 %.');
        self::assertSame(10.0, (float) $p['campaignDiscountPercent']);
    }

    public function testCollectionAppliesTheRightAdjustmentPerProduct(): void
    {
        $r = $this->request('GET', '/api/v1/products?search=' . $this->suffix . '&itemsPerPage=50', $this->discountUser);
        self::assertSame(200, $r['status'], json_encode($r['body']));

        $byId = [];
        foreach ($r['body']['member'] ?? [] as $row) {
            $byId[$row['id']] = $row;
        }
        self::assertSame([$this->p1->getId(), $this->p2->getId(), $this->p3->getId(), $this->p4->getId()], array_values(array_intersect(array_keys($byId), [$this->p1->getId(), $this->p2->getId(), $this->p3->getId(), $this->p4->getId()])), 'All four test products listed. Got ids: ' . implode(',', array_keys($byId)));
        self::assertSame(80.0, (float) $byId[$this->p1->getId()]['discountedPrice']);
        self::assertSame(60.0, (float) $byId[$this->p2->getId()]['discountedPrice']);
        self::assertSame(55.0, (float) $byId[$this->p3->getId()]['discountedPrice']);
        self::assertSame(75.0, (float) $byId[$this->p4->getId()]['discountedPrice']);
    }

    // --- order snapshot -------------------------------------------------------------

    public function testOrderWithMarkupSnapshotsTheRaisedPriceAsTheOriginal(): void
    {
        $r = $this->postOrder($this->markupUser, $this->p1, 2);
        self::assertSame(201, $r['status'], json_encode($r['body']));

        $item = $r['body']['items'][0];
        self::assertSame(120.0, (float) $item['unitPrice']);
        self::assertSame(120.0, (float) $item['originalUnitPrice'], 'The markup is the client\'s base price.');
        self::assertSame(0.0, (float) $item['discountPercent']);
        self::assertSame(240.0, (float) $item['subtotal']);
        self::assertSame(240.0, (float) $r['body']['subtotalBeforeDiscount']);
        self::assertSame(0.0, (float) $r['body']['totalDiscount']);
        self::assertSame(240.0, (float) $r['body']['totalAmount']);
    }

    public function testOrderWithDiscountSnapshotsOriginalPriceAndPercent(): void
    {
        $r = $this->postOrder($this->discountUser, $this->p1, 1);
        self::assertSame(201, $r['status'], json_encode($r['body']));

        $item = $r['body']['items'][0];
        self::assertSame(80.0, (float) $item['unitPrice']);
        self::assertSame(100.0, (float) $item['originalUnitPrice']);
        self::assertSame(20.0, (float) $item['discountPercent']);
        self::assertSame(100.0, (float) $r['body']['subtotalBeforeDiscount']);
        self::assertSame(20.0, (float) $r['body']['totalDiscount']);
        self::assertSame(80.0, (float) $r['body']['totalAmount']);
    }

    // --- admin write contract -------------------------------------------------------

    public function testDiscountWithoutClientsIsRejected(): void
    {
        $r = $this->request('POST', '/api/v1/discounts', $this->admin, [
            'name' => 'no clients -' . $this->suffix,
            'adjustmentPercent' => '-10.0000',
            'clients' => [],
        ]);

        self::assertSame(422, $r['status'], json_encode($r['body']));
        self::assertSame('clients', $r['body']['violations'][0]['propertyPath'] ?? null);
        self::assertSame('Select at least one client.', $r['body']['violations'][0]['message'] ?? null);
    }

    public function testZeroAdjustmentIsRejected(): void
    {
        $r = $this->request('POST', '/api/v1/discounts', $this->admin, [
            'name' => 'zero -' . $this->suffix,
            'adjustmentPercent' => '0',
            'clients' => ['/api/v1/clients/' . $this->discountClient->getId()],
        ]);

        self::assertSame(422, $r['status'], json_encode($r['body']));
        self::assertSame('adjustmentPercent', $r['body']['violations'][0]['propertyPath'] ?? null);
    }

    #[DataProvider('outOfRangeAdjustments')]
    public function testOutOfRangeAdjustmentIsRejected(string $value): void
    {
        $r = $this->request('POST', '/api/v1/discounts', $this->admin, [
            'name' => 'range -' . $this->suffix,
            'adjustmentPercent' => $value,
            'clients' => ['/api/v1/clients/' . $this->discountClient->getId()],
        ]);

        self::assertSame(422, $r['status'], json_encode($r['body']));
        self::assertSame('adjustmentPercent', $r['body']['violations'][0]['propertyPath'] ?? null);
    }

    /** @return iterable<string, array{string}> */
    public static function outOfRangeAdjustments(): iterable
    {
        yield '-100 (free)' => ['-100'];
        yield 'below -100' => ['-150.0000'];
        yield 'above 999' => ['1000'];
        yield 'not a number' => ['abc'];
    }

    public function testMissingAdjustmentIsRejected(): void
    {
        $r = $this->request('POST', '/api/v1/discounts', $this->admin, [
            'name' => 'missing -' . $this->suffix,
            'clients' => ['/api/v1/clients/' . $this->discountClient->getId()],
        ]);

        self::assertSame(422, $r['status'], json_encode($r['body']));
        self::assertSame('adjustmentPercent', $r['body']['violations'][0]['propertyPath'] ?? null);
    }

    public function testCreateReadRoundTripAndLegacyFieldsHaveNoEffect(): void
    {
        $r = $this->request('POST', '/api/v1/discounts', $this->admin, [
            'name' => 'created -' . $this->suffix,
            'adjustmentPercent' => '-12.5000',
            'clients' => ['/api/v1/clients/' . $this->discountClient->getId()],
            'productTypes' => ['ET'],
            'rules' => 'internal note',
            // Removed notions: must neither be stored nor break the request.
            'priority' => 7,
            'dateValidFrom' => '2020-01-01T00:00:00+00:00',
            'dateValidTo' => '2020-12-31T00:00:00+00:00',
            'accountGroups' => [],
            'discountPercent' => '99.0000',
        ]);
        self::assertSame(201, $r['status'], json_encode($r['body']));

        $expectedKeys = ['@context', '@id', '@type', 'id', 'name', 'isActive', 'adjustmentPercent', 'rules', 'productTypes', 'createdAt', 'updatedAt', 'clients'];
        self::assertEqualsCanonicalizing($expectedKeys, array_keys($r['body']), 'Exactly the new contract, nothing legacy.');
        self::assertSame('-12.5000', $r['body']['adjustmentPercent']);
        self::assertTrue($r['body']['isActive']);
        self::assertSame(['ET'], $r['body']['productTypes']);
        self::assertSame('internal note', $r['body']['rules']);
        self::assertSame(['/api/v1/clients/' . $this->discountClient->getId()], $r['body']['clients']);

        $row = $this->em->getConnection()->fetchAssociative('SELECT * FROM discount WHERE id = :id', ['id' => $r['body']['id']]);
        self::assertSame('-12.5000', $row['adjustment_percent']);
        self::assertArrayNotHasKey('priority', $row);
        self::assertArrayNotHasKey('date_valid_from', $row);
        self::assertArrayNotHasKey('discount_percent', $row);

        $get = $this->request('GET', '/api/v1/discounts/' . $r['body']['id'], $this->admin);
        self::assertSame(200, $get['status']);
        self::assertSame('-12.5000', $get['body']['adjustmentPercent']);
    }

    public function testListFiltersAndOrdersByAdjustment(): void
    {
        $r = $this->request('GET', '/api/v1/discounts?name=-' . $this->suffix . '&isActive=true&order[adjustmentPercent]=asc&itemsPerPage=50', $this->admin);
        self::assertSame(200, $r['status'], json_encode($r['body']));

        $percents = array_map(static fn (array $d) => (float) $d['adjustmentPercent'], $r['body']['member']);
        self::assertSame(7, $r['body']['totalItems'], 'All active test discounts, the inactive one filtered out.');
        self::assertSame([-40.0, -30.0, -20.0, -10.0, -5.0, 10.0, 20.0], $percents);
    }

    public function testProductDiscountHasNoValidityDates(): void
    {
        $discountId = (int) $this->em->getConnection()->fetchOne('SELECT id FROM discount WHERE name = :n', ['n' => 'wide -20 -' . $this->suffix]);
        $r = $this->request('POST', '/api/v1/product_discounts', $this->admin, [
            'productId' => $this->p1->getId(),
            'discountId' => $discountId,
            'rebate' => '-5.0000',
            'dateValidFrom' => '2020-01-01T00:00:00+00:00',
        ]);

        self::assertSame(201, $r['status'], json_encode($r['body']));
        self::assertArrayNotHasKey('dateValidFrom', $r['body']);
        self::assertArrayNotHasKey('dateValidTo', $r['body']);
        self::assertSame('-5.0000', $r['body']['rebate']);
    }

    public function testAccountGroupsEndpointIsGone(): void
    {
        $r = $this->request('GET', '/api/v1/account_groups', $this->admin);
        self::assertSame(404, $r['status']);

        $client = $this->request('GET', '/api/v1/clients/' . $this->discountClient->getId(), $this->admin);
        self::assertSame(200, $client['status']);
        self::assertArrayNotHasKey('accountGroup', $client['body']);
    }

    public function testShopUserCannotWriteDiscounts(): void
    {
        $r = $this->request('POST', '/api/v1/discounts', $this->discountUser, [
            'name' => 'forbidden -' . $this->suffix,
            'adjustmentPercent' => '-90.0000',
            'clients' => ['/api/v1/clients/' . $this->discountClient->getId()],
        ]);

        self::assertSame(403, $r['status']);
    }

    // --- helpers ------------------------------------------------------------

    /** @return array<string,mixed> */
    private function getProduct(Product $product, User $as): array
    {
        $r = $this->request('GET', '/api/v1/products/' . $product->getId(), $as);
        self::assertSame(200, $r['status'], json_encode($r['body']));

        return $r['body'];
    }

    /** @return array{status:int, body:array<mixed>} */
    private function postOrder(User $as, Product $product, int $qty): array
    {
        return $this->request('POST', '/api/v1/orders', $as, [
            'isDraft' => false,
            'items' => [['product' => '/api/v1/products/' . $product->getId(), 'quantity' => $qty]],
        ]);
    }

    private function makeClient(string $tag): Client
    {
        $c = (new Client())->setName('Client ' . $tag . ' ' . $this->suffix)->setCode($tag . '-' . $this->suffix);
        $this->em->persist($c);

        return $c;
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
            ->setName('Disc ' . $tag . ' ' . $this->suffix)
            ->setSlug('disc-' . $tag . '-' . $this->suffix)
            ->setPartNo('DISC-' . strtoupper($tag) . '-' . $this->suffix)
            ->setPrice(100.0);
        $this->em->persist($p);

        return $p;
    }

    /** @param Client[] $clients */
    private function makeDiscount(string $name, string $adjustmentPercent, array $clients): Discount
    {
        $d = (new Discount())
            ->setName($name . ' -' . $this->suffix)
            ->setAdjustmentPercent($adjustmentPercent);
        foreach ($clients as $client) {
            $d->addClient($client);
        }
        $this->em->persist($d);

        return $d;
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
