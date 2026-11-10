<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Address;
use App\Entity\Client;
use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * `hasActiveDeliveryAddress` is a virtual (getter-only) property, so this checks that
 * API Platform really serializes it where the frontends read it: the client resource
 * (admin list/detail) and /api/me (webshop banner).
 */
final class ClientDeliveryAddressFlagApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;
    private Client $company;
    private User $member;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = bin2hex(random_bytes(4));

        $this->company = (new Client())
            ->setName('Address Flag Test ' . $this->suffix)
            ->setCode('AFT-' . $this->suffix);
        $this->em->persist($this->company);
        $this->member = $this->makeUser('member', ['ROLE_CLIENT'], $this->company);
        $this->admin = $this->makeUser('admin', ['ROLE_ADMIN'], null);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->em) && $this->em->isOpen()) {
                $like = '%-' . $this->suffix;
                $this->em->createQuery('DELETE App\Entity\Address a WHERE IDENTITY(a.client) = :id')
                    ->setParameter('id', $this->company->getId())->execute();
                $this->em->createQuery('DELETE App\Entity\User u WHERE u.email LIKE :s')
                    ->setParameter('s', $like)->execute();
                $this->em->createQuery('DELETE App\Entity\Client c WHERE c.code LIKE :s')
                    ->setParameter('s', $like)->execute();
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testFlagFollowsTheActiveDeliveryAddresses(): void
    {
        $id = $this->company->getId();

        self::assertFalse($this->meFlag(), 'no address yet → /api/me says false');
        self::assertFalse($this->clientFlag($id), 'no address yet → client resource says false');

        $this->addAddress(isDelivery: false, isActive: true);   // billing only
        self::assertFalse($this->meFlag(), 'billing-only address does not count');

        $this->addAddress(isDelivery: true, isActive: false);   // inactive delivery
        self::assertFalse($this->clientFlag($id), 'inactive delivery address does not count');

        $this->addAddress(isDelivery: true, isActive: true);
        self::assertTrue($this->meFlag(), 'active delivery address → /api/me says true');
        self::assertTrue($this->clientFlag($id), 'active delivery address → client resource says true');
    }

    // --- helpers ------------------------------------------------------------

    private function meFlag(): ?bool
    {
        $response = $this->request('GET', '/api/me', $this->member);
        self::assertSame(200, $response['status']);
        self::assertArrayHasKey('hasActiveDeliveryAddress', $response['body']['client'] ?? []);

        return $response['body']['client']['hasActiveDeliveryAddress'];
    }

    private function clientFlag(int $id): ?bool
    {
        $response = $this->request('GET', '/api/v1/clients/' . $id, $this->admin);
        self::assertSame(200, $response['status']);
        self::assertArrayHasKey('hasActiveDeliveryAddress', $response['body']);

        return $response['body']['hasActiveDeliveryAddress'];
    }

    private function addAddress(bool $isDelivery, bool $isActive): void
    {
        $address = (new Address())
            ->setStreet('Teststraße 1')
            ->setCity('Weissenbach')
            ->setIsBilling(!$isDelivery)
            ->setIsDelivery($isDelivery)
            ->setIsActive($isActive);
        // Requests clear the EntityManager, so re-attach the fixture before linking to it.
        $company = $this->em->find(Client::class, $this->company->getId());
        self::assertNotNull($company);
        $company->addAddress($address);
        $this->em->persist($address);
        $this->em->flush();
    }

    /** @return array{status:int, body:array<mixed>} */
    private function request(string $method, string $url, User $as): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($as);
        $this->client->request($method, $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        $response = $this->client->getResponse();
        $body = json_decode($response->getContent() ?: 'null', true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
    }

    /** @param string[] $roles */
    private function makeUser(string $tag, array $roles, ?Client $client): User
    {
        $user = (new User())
            ->setEmail($tag . '-' . $this->suffix)
            ->setFirstName('Test')
            ->setLastName($tag);
        $user->setPassword('not-used-in-tests');
        $user->setRoles($roles);
        if ($client !== null) {
            $user->setClient($client);
        }
        $this->em->persist($user);

        return $user;
    }
}
