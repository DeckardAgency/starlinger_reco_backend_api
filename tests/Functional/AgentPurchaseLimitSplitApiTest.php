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
 * Regression coverage for the client-agent purchase-limit split.
 *
 * When an agent orders "on behalf of" managed clients, the spend must be debited
 * against each MANAGED client (split per item), NOT the agent's own client. A
 * mixed-client order where one client would exceed its limit must be rejected in
 * full, with nothing debited (per-client debits run in one transaction).
 */
final class AgentPurchaseLimitSplitApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;

    private Client $agentClient;
    private Client $clientB;
    private Client $clientC;
    private User $agentUser;
    private Product $product;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->suffix = bin2hex(random_bytes(4));

        // Agent company that manages B and C.
        $this->agentClient = (new Client())->setName('Agent ' . $this->suffix)->setCode('AGENT-' . $this->suffix);
        $this->agentClient->setIsClientAgent(true);
        $this->clientB = $this->makeManagedClient('B', '1000.00');
        $this->clientC = $this->makeManagedClient('C', '1000.00');
        $this->agentClient->addManagedClient($this->clientB);
        $this->agentClient->addManagedClient($this->clientC);
        $this->em->persist($this->agentClient);

        $this->agentUser = (new User())
            ->setEmail('agent-' . $this->suffix)
            ->setFirstName('Test')->setLastName('Agent');
        $this->agentUser->setPassword('not-used');
        $this->agentUser->setRoles(['ROLE_USER_CLIENT_AGENT']);
        $this->agentUser->setClient($this->agentClient);
        $this->em->persist($this->agentUser);

        $this->product = (new Product())
            ->setName('Prod ' . $this->suffix)->setSlug('prod-' . $this->suffix)->setPrice(100.0);
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

    public function testMixedOrderDebitsEachManagedClientNotTheAgent(): void
    {
        $response = $this->postOrder([
            $this->itemFor($this->clientB, 2), // 2 x 100
            $this->itemFor($this->clientC, 3), // 3 x 100
        ]);

        self::assertSame(201, $response['status'], 'Agent order should succeed. Body: ' . json_encode($response['body']));

        $spentB = $this->spentOf($this->clientB->getId());
        $spentC = $this->spentOf($this->clientC->getId());
        $spentAgent = $this->spentOf($this->agentClient->getId());

        self::assertGreaterThan(0, $spentB, 'Managed client B must be debited.');
        self::assertGreaterThan(0, $spentC, 'Managed client C must be debited.');
        self::assertSame(0.0, $spentAgent, 'The AGENT client must NOT be debited.');
        // C ordered more (qty 3 vs 2), so its debit is larger — proves per-item split.
        self::assertGreaterThan($spentB, $spentC, 'Per-client split should reflect each client\'s items.');
    }

    public function testMixedOrderRejectedInFullWhenOneClientOverLimit(): void
    {
        // Tighten B's limit so its line (1 x 100) exceeds it; C stays well within.
        $this->em->getConnection()->executeStatement(
            'UPDATE client SET purchase_limit = 50 WHERE id = :id',
            ['id' => $this->clientB->getId()]
        );

        $response = $this->postOrder([
            $this->itemFor($this->clientB, 1), // 100 > B limit 50 -> must reject
            $this->itemFor($this->clientC, 1), // within C limit
        ]);

        self::assertSame(400, $response['status'], 'Order exceeding a client limit must be rejected.');
        self::assertSame(0.0, $this->spentOf($this->clientB->getId()), 'B must not be debited on rejection.');
        self::assertSame(0.0, $this->spentOf($this->clientC->getId()), 'C must not be debited (whole order rolls back).');
    }

    // --- helpers ------------------------------------------------------------

    private function makeManagedClient(string $tag, string $limit): Client
    {
        $c = (new Client())->setName('Managed ' . $tag . ' ' . $this->suffix)->setCode($tag . '-' . $this->suffix);
        $c->setPurchaseLimit($limit);
        $c->setAmountSpent('0.00');
        $this->em->persist($c);

        return $c;
    }

    /** @return array<string,mixed> */
    private function itemFor(Client $client, int $qty): array
    {
        return [
            'product' => '/api/v1/products/' . $this->product->getId(),
            'quantity' => $qty,
            'onBehalfOfClient' => '/api/v1/clients/' . $client->getId(),
        ];
    }

    /**
     * @param array<int,array<string,mixed>> $items
     * @return array{status:int, body:array<mixed>}
     */
    private function postOrder(array $items): array
    {
        $this->client->request('POST', '/api/v1/orders', [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()
                ->get(JWTTokenManagerInterface::class)->create($this->agentUser),
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode(['isDraft' => false, 'items' => $items], JSON_THROW_ON_ERROR));

        $response = $this->client->getResponse();
        $body = json_decode($response->getContent() ?: 'null', true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
    }

    private function spentOf(int $clientId): float
    {
        return (float) $this->em->getConnection()->fetchOne(
            'SELECT COALESCE(amount_spent, 0) FROM client WHERE id = :id',
            ['id' => $clientId]
        );
    }
}
