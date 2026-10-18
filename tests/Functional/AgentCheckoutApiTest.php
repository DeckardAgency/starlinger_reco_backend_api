<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Address;
use App\Entity\Client;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;

/**
 * End-to-end coverage of the multi-client checkout (`POST /orders/checkout`),
 * the agent address read scope and the "documents name the right company" rule.
 *
 * Modelled on AgentPurchaseLimitSplitApiTest: WebTestCase, JWTs minted via the
 * token manager, own fixtures with a per-run suffix, cleaned up in tearDown.
 */
final class AgentCheckoutApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;

    private Client $agentClient;
    private Client $clientB;
    private Client $clientC;
    /** Not managed by the agent. */
    private Client $clientX;
    /** Ordinary (non-agent) company. */
    private Client $clientD;
    private User $agentUser;
    private User $plainUser;
    private Product $product;

    private Address $agentDelivery;
    private Address $bDelivery;
    private Address $bBilling;
    private Address $cDelivery;
    private Address $cBilling;
    private Address $xDelivery;
    private Address $dDelivery;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->suffix = bin2hex(random_bytes(4));

        $this->agentClient = $this->makeClient('AGENT', null);
        $this->agentClient->setIsClientAgent(true);
        $this->clientB = $this->makeClient('B', '1000.00');
        $this->clientC = $this->makeClient('C', '1000.00');
        $this->clientX = $this->makeClient('X', null);
        $this->clientD = $this->makeClient('D', null);
        $this->agentClient->addManagedClient($this->clientB);
        $this->agentClient->addManagedClient($this->clientC);

        $this->agentDelivery = $this->makeAddress($this->agentClient, 'Agent Street 1', 'Agentville', true, false);
        $this->bDelivery = $this->makeAddress($this->clientB, 'B Delivery Road 2', 'Btown', true, false);
        $this->bBilling = $this->makeAddress($this->clientB, 'B Billing Lane 3', 'Btown', false, true);
        $this->cDelivery = $this->makeAddress($this->clientC, 'C Delivery Road 4', 'Ccity', true, false);
        $this->cBilling = $this->makeAddress($this->clientC, 'C Billing Lane 5', 'Ccity', false, true);
        $this->xDelivery = $this->makeAddress($this->clientX, 'X Street 6', 'Xburg', true, false);
        $this->dDelivery = $this->makeAddress($this->clientD, 'D Street 7', 'Dorf', true, false);

        // A real agent user is also a webshop user (ROLE_CLIENT), which lets the
        // PATCH test exercise the ownership scope rather than access_control.
        $this->agentUser = $this->makeUser('agent', ['ROLE_USER_CLIENT_AGENT', 'ROLE_CLIENT'], $this->agentClient);
        $this->plainUser = $this->makeUser('plain', ['ROLE_CLIENT'], $this->clientD);

        $this->product = (new Product())
            ->setName('Prod ' . $this->suffix)
            ->setSlug('prod-' . $this->suffix)
            ->setPartNo('PN-' . $this->suffix)
            ->setPrice(100.0);
        $this->em->persist($this->product);

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

    // --- 1. mixed cart -------------------------------------------------------

    public function testMixedCartCreatesOneOrderPerClientWithItsOwnAddresses(): void
    {
        $messagesBefore = $this->messengerCount();

        $r = $this->checkout($this->agentUser, [
            'isDraft' => false,
            'customerReference' => 'PO-' . $this->suffix,
            'notes' => 'Deliver before noon',
            'paymentType' => null,
            'deliveryType' => null,
            'groups' => [
                $this->group($this->clientB, $this->bDelivery, null, 2),              // 2 x 100, default billing
                $this->group($this->clientC, $this->cDelivery, $this->cBilling, 3),   // 3 x 100, explicit billing
                $this->group(null, $this->agentDelivery, null, 1),                   // own company, no billing address
            ],
        ]);

        self::assertSame(201, $r['status'], 'Body: ' . json_encode($r['body']));
        $reference = $r['body']['checkoutReference'] ?? '';
        self::assertMatchesRegularExpression('/^CHK-\d{8}-[A-Z0-9]{6}$/', $reference);
        self::assertCount(3, $r['body']['orders']);

        $byClient = [];
        foreach ($r['body']['orders'] as $order) {
            self::assertSame($reference, $order['checkoutReference'], 'All orders share the checkout reference.');
            self::assertSame('PO-' . $this->suffix, $order['customerReference']);
            self::assertSame('Deliver before noon', $order['notes']);
            self::assertSame('new', $order['status']);
            self::assertCount(1, $order['items']);
            $byClient[$order['onBehalfOfClient']['id'] ?? 'own'] = $order;
        }
        self::assertEqualsCanonicalizing(['own', $this->clientB->getId(), $this->clientC->getId()], array_keys($byClient));

        $b = $byClient[$this->clientB->getId()];
        self::assertSame($this->bDelivery->getId(), $b['shippingAddressId']);
        self::assertSame($this->bDelivery->getFullAddress(), $b['shippingAddress']);
        self::assertSame($this->bBilling->getFullAddress(), $b['billingAddress'], 'Default billing = B\'s first active billing address.');
        self::assertSame(2, $b['items'][0]['quantity']);
        self::assertSame($this->clientB->getId(), $b['items'][0]['onBehalfOfClient']['id'], 'Items are attributed to the group\'s client.');
        self::assertSame(200.0, (float) $b['totalAmount']);

        $c = $byClient[$this->clientC->getId()];
        self::assertSame($this->cDelivery->getFullAddress(), $c['shippingAddress']);
        self::assertSame($this->cBilling->getFullAddress(), $c['billingAddress']);
        self::assertSame(3, $c['items'][0]['quantity']);
        self::assertSame($this->clientC->getId(), $c['items'][0]['onBehalfOfClient']['id']);
        self::assertSame(300.0, (float) $c['totalAmount']);

        $own = $byClient['own'];
        self::assertNull($own['onBehalfOfClient'] ?? null);
        self::assertSame($this->agentDelivery->getFullAddress(), $own['shippingAddress']);
        self::assertNull($own['billingAddress'] ?? null, 'No billing address on file -> text stays null.');
        self::assertNull($own['items'][0]['onBehalfOfClient'] ?? null);

        // Purchase limits debited per client (not the agent for managed groups).
        self::assertSame(200.0, $this->spentOf($this->clientB->getId()));
        self::assertSame(300.0, $this->spentOf($this->clientC->getId()));
        self::assertSame(100.0, $this->spentOf($this->agentClient->getId()), 'Only the own-company group hits the agent\'s ledger.');

        // Persisted as 3 orders sharing the reference; one OrderCreatedMessage each, after commit.
        self::assertSame(3, $this->orderCountFor($this->agentUser));
        self::assertSame(3, (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM `order` WHERE checkout_reference = :r AND is_draft = 0 AND status = \'new\'',
            ['r' => $reference]
        ));
        self::assertSame($messagesBefore + 3, $this->messengerCount());

        // The orders are listable together via the new filter.
        $list = $this->request('GET', '/api/v1/orders?checkoutReference=' . $reference, $this->agentUser);
        self::assertSame(200, $list['status']);
        self::assertSame(3, $list['body']['totalItems'] ?? null);
    }

    // --- 2. all-or-nothing on purchase limit -----------------------------------

    public function testOneClientOverItsLimitRollsBackTheWholeCheckout(): void
    {
        $this->em->getConnection()->executeStatement(
            'UPDATE client SET purchase_limit = 50 WHERE id = :id',
            ['id' => $this->clientB->getId()]
        );
        $messagesBefore = $this->messengerCount();

        // C first so it is already persisted + debited when B fails.
        $r = $this->checkout($this->agentUser, [
            'isDraft' => false,
            'groups' => [
                $this->group($this->clientC, $this->cDelivery, null, 1),
                $this->group($this->clientB, $this->bDelivery, null, 1), // 100 > limit 50
            ],
        ]);

        self::assertSame(400, $r['status'], 'Body: ' . json_encode($r['body']));
        self::assertStringContainsString('purchase limit', $r['body']['detail'] ?? '');
        self::assertSame(0, $this->orderCountFor($this->agentUser), 'ZERO orders persisted.');
        self::assertSame(0.0, $this->spentOf($this->clientB->getId()));
        self::assertSame(0.0, $this->spentOf($this->clientC->getId()), 'C\'s debit rolled back with the batch.');
        self::assertSame($messagesBefore, $this->messengerCount(), 'No notification for a rolled-back checkout.');
    }

    // --- 3. address must belong to the group's client ---------------------------

    public function testAddressOfAnotherClientIsRejected(): void
    {
        $r = $this->checkout($this->agentUser, [
            'isDraft' => false,
            'groups' => [
                $this->group($this->clientC, $this->cDelivery, null, 1),
                $this->group($this->clientB, $this->cDelivery, null, 1), // C's address on B's group
            ],
        ]);

        self::assertSame(400, $r['status'], 'Body: ' . json_encode($r['body']));
        self::assertStringContainsString('not an active delivery address', $r['body']['detail'] ?? '');
        self::assertStringContainsString('groups[1]', $r['body']['detail'] ?? '');
        self::assertSame(0, $this->orderCountFor($this->agentUser));
        self::assertSame(0.0, $this->spentOf($this->clientC->getId()));

        // Same for a billing address of another client.
        $r = $this->checkout($this->agentUser, [
            'groups' => [$this->group($this->clientB, $this->bDelivery, $this->cBilling, 1)],
        ]);
        self::assertSame(400, $r['status']);
        self::assertStringContainsString('not an active billing address', $r['body']['detail'] ?? '');
        self::assertSame(0, $this->orderCountFor($this->agentUser));

        // And a duplicate client across groups.
        $r = $this->checkout($this->agentUser, [
            'groups' => [
                $this->group($this->clientB, $this->bDelivery, null, 1),
                $this->group($this->clientB, $this->bDelivery, null, 1),
            ],
        ]);
        self::assertSame(400, $r['status']);
        self::assertStringContainsString('more than one group', $r['body']['detail'] ?? '');
        self::assertSame(0, $this->orderCountFor($this->agentUser));
    }

    // --- 4. non-agents may not delegate -----------------------------------------

    public function testNonAgentSendingOnBehalfOfClientIsForbidden(): void
    {
        $r = $this->checkout($this->plainUser, [
            'groups' => [$this->group($this->clientB, $this->bDelivery, null, 1)],
        ]);

        self::assertSame(403, $r['status'], 'Body: ' . json_encode($r['body']));
        self::assertSame(0, $this->orderCountFor($this->plainUser));
        self::assertSame(0.0, $this->spentOf($this->clientB->getId()));

        // ...but an ordinary user can check out for their own company (here with
        // Accept: application/json — the endpoint serves plain JSON as well as JSON-LD).
        $r = $this->request('POST', '/api/v1/orders/checkout', $this->plainUser, [
            'groups' => [$this->group(null, $this->dDelivery, null, 1)],
        ], 'application/json', 'application/json');
        self::assertSame(201, $r['status'], 'Body: ' . json_encode($r['body']));
        self::assertCount(1, $r['body']['orders']);
        self::assertNull($r['body']['orders'][0]['onBehalfOfClient'] ?? null);
        self::assertSame($this->dDelivery->getFullAddress(), $r['body']['orders'][0]['shippingAddress']);
        self::assertSame(1, $this->orderCountFor($this->plainUser));
    }

    // --- 5. agents read managed clients' addresses, nothing more ---------------

    public function testAgentReadsManagedClientsAddressesButCannotWriteThem(): void
    {
        $r = $this->request('GET', '/api/v1/clients/' . $this->clientB->getId() . '/addresses', $this->agentUser);
        self::assertSame(200, $r['status']);
        self::assertSame(2, $r['body']['totalItems'] ?? null);
        self::assertEqualsCanonicalizing(
            [$this->bDelivery->getId(), $this->bBilling->getId()],
            array_column($r['body']['member'], 'id')
        );

        // Not managed: nothing leaks.
        $r = $this->request('GET', '/api/v1/clients/' . $this->clientX->getId() . '/addresses', $this->agentUser);
        self::assertSame(200, $r['status']);
        self::assertSame(0, $r['body']['totalItems'] ?? null);

        // Flat collection, filtered by client.
        $r = $this->request('GET', '/api/v1/addresses?client.id=' . $this->clientC->getId(), $this->agentUser);
        self::assertSame(200, $r['status']);
        self::assertEqualsCanonicalizing(
            [$this->cDelivery->getId(), $this->cBilling->getId()],
            array_column($r['body']['member'], 'id')
        );

        // Flat collection, unfiltered: own + managed only (never X or D).
        $r = $this->request('GET', '/api/v1/addresses?itemsPerPage=100', $this->agentUser);
        self::assertSame(200, $r['status']);
        $ids = array_column($r['body']['member'], 'id');
        foreach ([$this->agentDelivery, $this->bDelivery, $this->bBilling, $this->cDelivery, $this->cBilling] as $visible) {
            self::assertContains($visible->getId(), $ids);
        }
        self::assertNotContains($this->xDelivery->getId(), $ids);
        self::assertNotContains($this->dDelivery->getId(), $ids);

        // Single managed address is readable...
        $r = $this->request('GET', '/api/v1/addresses/' . $this->bDelivery->getId(), $this->agentUser);
        self::assertSame(200, $r['status']);

        // ...but not writable: PATCH stays scoped to the agent's own client.
        $r = $this->request('PATCH', '/api/v1/addresses/' . $this->bDelivery->getId(), $this->agentUser, ['name' => 'hacked'], 'application/merge-patch+json');
        self::assertContains($r['status'], [403, 404], 'Body: ' . json_encode($r['body']));
        self::assertNull($this->em->getConnection()->fetchOne('SELECT name FROM address WHERE id = :id', ['id' => $this->bDelivery->getId()]));

        // A non-agent sees only their own company's addresses.
        $r = $this->request('GET', '/api/v1/clients/' . $this->clientB->getId() . '/addresses', $this->plainUser);
        self::assertSame(200, $r['status']);
        self::assertSame(0, $r['body']['totalItems'] ?? null);
    }

    // --- 6. documents name the company ordered for ------------------------------

    public function testOrderDocumentsNameTheCompanyOrderedFor(): void
    {
        /** @var Environment $twig */
        $twig = self::getContainer()->get('twig');

        $onBehalf = $this->inMemoryOrder($this->agentUser, $this->clientB, $this->bDelivery->getFullAddress(), null);

        $pdf = $twig->render('pdf/order.html.twig', ['order' => $onBehalf, 'generatedAt' => new \DateTime()]);
        self::assertStringContainsString('Company:', $pdf);
        self::assertStringContainsString($this->clientB->getName(), $pdf);
        self::assertStringContainsString('Ordered by (agent):', $pdf);
        self::assertStringContainsString($this->agentClient->getName(), $pdf);
        self::assertStringContainsString($this->agentUser->getEmail(), $pdf);
        self::assertStringContainsString($this->bDelivery->getFullAddress(), $pdf);
        self::assertStringContainsString('Billing Address:', $pdf, 'Row prints even when empty…');
        self::assertStringContainsString('—', $pdf, '…showing a dash.');

        $email = $twig->render('emails/admin/order_submitted.html.twig', [
            'order' => $onBehalf,
            'items' => [],
            'totalAmount' => $onBehalf->getTotalAmount(),
            'orderHistory' => [],
            'base_url' => 'http://localhost',
            'createdBy' => null,
            'createdByName' => 'Test',
        ]);
        self::assertStringContainsString('Company:</strong> ' . $this->clientB->getName(), $email);
        self::assertStringContainsString('Ordered by (agent):', $email);
        self::assertStringContainsString($this->agentClient->getName(), $email);
        self::assertStringContainsString('Shipping Address:</strong> ' . $this->bDelivery->getFullAddress(), $email);
        self::assertStringContainsString('Billing Address:</strong> —', $email);

        // An ordinary order names the user's own company and has no agent row.
        $own = $this->inMemoryOrder($this->plainUser, null, $this->dDelivery->getFullAddress(), 'Some Billing 9, Dorf');
        $pdf = $twig->render('pdf/order.html.twig', ['order' => $own, 'generatedAt' => new \DateTime()]);
        self::assertStringContainsString($this->clientD->getName(), $pdf);
        self::assertStringNotContainsString('Ordered by (agent)', $pdf);
        self::assertStringContainsString('Some Billing 9, Dorf', $pdf);
    }

    // --- helpers ------------------------------------------------------------

    private function makeClient(string $tag, ?string $limit): Client
    {
        $c = (new Client())->setName('Client ' . $tag . ' ' . $this->suffix)->setCode($tag . '-' . $this->suffix);
        if ($limit !== null) {
            $c->setPurchaseLimit($limit);
            $c->setAmountSpent('0.00');
        }
        $this->em->persist($c);

        return $c;
    }

    private function makeAddress(Client $client, string $street, string $city, bool $delivery, bool $billing): Address
    {
        $a = (new Address())
            ->setClient($client)
            ->setStreet($street)
            ->setCity($city)
            ->setPostalCode('1000')
            ->setIsDelivery($delivery)
            ->setIsBilling($billing);
        $this->em->persist($a);

        return $a;
    }

    /** @param string[] $roles */
    private function makeUser(string $tag, array $roles, Client $client): User
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

    /** @return array<string,mixed> */
    private function group(?Client $onBehalfOf, Address $shipping, ?Address $billing, int $qty): array
    {
        return [
            'onBehalfOfClient' => $onBehalfOf ? '/api/v1/clients/' . $onBehalfOf->getId() : null,
            'shippingAddressId' => $shipping->getId(),
            'billingAddressId' => $billing?->getId(),
            'items' => [['product' => '/api/v1/products/' . $this->product->getId(), 'quantity' => $qty]],
        ];
    }

    /**
     * @param array<string,mixed> $payload
     * @return array{status:int, body:array<mixed>}
     */
    private function checkout(User $as, array $payload): array
    {
        // Plain application/json on purpose: the endpoint must accept it as well as ld+json.
        return $this->request('POST', '/api/v1/orders/checkout', $as, $payload, 'application/json');
    }

    /** @return array{status:int, body:array<mixed>} */
    private function request(string $method, string $url, User $as, ?array $json = null, string $contentType = 'application/ld+json', string $accept = 'application/ld+json'): array
    {
        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($as),
            'HTTP_ACCEPT' => $accept,
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

    private function inMemoryOrder(User $user, ?Client $onBehalfOf, string $shipping, ?string $billing): Order
    {
        $order = (new Order())
            ->setUser($user)
            ->setOnBehalfOfClient($onBehalfOf)
            ->setIsDraft(false)
            ->setShippingAddress($shipping)
            ->setBillingAddress($billing)
            ->setCreatedAt(new \DateTime())
            ->setUpdatedAt(new \DateTime());
        $item = (new OrderItem())->setProduct($this->product)->setQuantity(2)->setUnitPrice(100.0);
        $item->setOnBehalfOfClient($onBehalfOf);
        $order->addItem($item);

        return $order;
    }

    private function spentOf(int $clientId): float
    {
        return (float) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(amount_spent, 0) FROM client WHERE id = :id',
            ['id' => $clientId]
        );
    }

    private function orderCountFor(User $user): int
    {
        return (int) $this->em->getConnection()->fetchOne(
            'SELECT COUNT(*) FROM `order` WHERE user_id = :id',
            ['id' => $user->getId()]
        );
    }

    private function messengerCount(): int
    {
        return (int) $this->em->getConnection()->fetchOne('SELECT COUNT(*) FROM messenger_messages');
    }
}
