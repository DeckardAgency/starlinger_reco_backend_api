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
 * End-to-end coverage of the client-agent delegation contract over HTTP.
 *
 * Uses WebTestCase (no api-platform createClient deprecation) and mints JWTs via
 * the token manager (no login_check, so the login rate-limiter never trips).
 * Relies on the demo data seeded by `app:create-agent-fixtures`; the whole class
 * skips if that data isn't present, so the suite stays green on a bare DB.
 */
final class ClientAgentApiTest extends WebTestCase
{
    private const AGENT_EMAIL = 'agent1@agent-demo.com';
    private const NON_AGENT_EMAIL = 'user@mc-demo-001.com';

    private KernelBrowser $client;
    private EntityManagerInterface $em;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        // Keep one booted kernel across requests so the EntityManager stays valid.
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        if ($this->em->getRepository(User::class)->findOneBy(['email' => self::AGENT_EMAIL]) === null) {
            self::markTestSkipped('Agent demo fixtures not loaded (run app:create-agent-fixtures).');
        }
    }

    public function testAgentSeesManagedClients(): void
    {
        $response = $this->request('GET', '/api/v1/agent/managed-clients', self::AGENT_EMAIL);

        self::assertSame(200, $response['status']);
        self::assertSame(3, $response['body']['totalItems'] ?? null);
    }

    public function testNonAgentIsForbiddenFromManagedClients(): void
    {
        $response = $this->request('GET', '/api/v1/agent/managed-clients', self::NON_AGENT_EMAIL);

        self::assertSame(403, $response['status']);
    }

    public function testAgentCanOrderOnBehalfOfManagedClient(): void
    {
        $managed = $this->clientByCode('MC-DEMO-001');
        $response = $this->request('POST', '/api/v1/orders', self::AGENT_EMAIL, $this->orderPayload($managed->getId(), $managed->getId()));

        self::assertSame(201, $response['status']);
        self::assertSame($managed->getId(), $response['body']['onBehalfOfClient']['id'] ?? null);
        self::assertSame($managed->getId(), $response['body']['items'][0]['onBehalfOfClient']['id'] ?? null);
    }

    public function testAgentCannotOrderOnBehalfOfUnmanagedClient(): void
    {
        $unmanaged = $this->unmanagedClient();
        $response = $this->request('POST', '/api/v1/orders', self::AGENT_EMAIL, $this->orderPayload($unmanaged->getId(), null));

        self::assertSame(403, $response['status']);
    }

    public function testNonAgentDelegationIsStripped(): void
    {
        $managed = $this->clientByCode('MC-DEMO-001');
        $response = $this->request('POST', '/api/v1/orders', self::NON_AGENT_EMAIL, $this->orderPayload($managed->getId(), $managed->getId()));

        // The line is accepted but the delegation is stripped server-side.
        self::assertSame(201, $response['status']);
        self::assertNull($response['body']['onBehalfOfClient'] ?? null);
        self::assertNull($response['body']['items'][0]['onBehalfOfClient'] ?? null);
    }

    // --- helpers ------------------------------------------------------------

    /** @return array{status:int, body:array<mixed>} */
    private function request(string $method, string $url, string $email, ?array $json = null): array
    {
        $server = [
            'HTTP_AUTHORIZATION' => 'Bearer '.$this->tokenFor($email),
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

    private function tokenFor(string $email): string
    {
        $user = $this->em->getRepository(User::class)->findOneBy(['email' => $email]);
        self::assertNotNull($user, "Missing seeded user $email");

        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    /** @return array<string,mixed> */
    private function orderPayload(int $itemClientId, ?int $orderClientId): array
    {
        $product = $this->getOrCreateProduct();

        $payload = [
            'isDraft' => true,
            'items' => [[
                'product' => '/api/v1/products/'.$product->getId(),
                'quantity' => 1,
                'onBehalfOfClient' => '/api/v1/clients/'.$itemClientId,
            ]],
        ];
        if ($orderClientId !== null) {
            $payload['onBehalfOfClient'] = '/api/v1/clients/'.$orderClientId;
        }

        return $payload;
    }

    private function clientByCode(string $code): Client
    {
        $client = $this->em->getRepository(Client::class)->findOneBy(['code' => $code]);
        self::assertNotNull($client, "Missing seeded client $code");

        return $client;
    }

    private function unmanagedClient(): Client
    {
        $agent = $this->clientByCode('AGENT-DEMO');
        $managedIds = array_map(static fn (Client $c) => $c->getId(), $agent->getManagedClients()->toArray());
        $managedIds[] = $agent->getId();

        foreach ($this->em->getRepository(Client::class)->findBy(['isArchived' => false]) as $client) {
            if (!in_array($client->getId(), $managedIds, true)) {
                return $client;
            }
        }

        $client = (new Client())->setName('Unmanaged Test')->setCode('UNMANAGED-TEST');
        $this->em->persist($client);
        $this->em->flush();

        return $client;
    }

    private function getOrCreateProduct(): Product
    {
        $product = $this->em->getRepository(Product::class)->findOneBy([]);
        if ($product !== null) {
            return $product;
        }

        $product = (new Product())
            ->setName('Test Product')
            ->setSlug('test-product-'.bin2hex(random_bytes(4)))
            ->setPrice(9.99);
        $this->em->persist($product);
        $this->em->flush();

        return $product;
    }
}
