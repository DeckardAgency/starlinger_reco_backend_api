<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Client;
use App\Entity\User;
use App\Entity\UserInvitation;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Regression coverage for the multi-tenant read-isolation fixes:
 *  - UserInvitation: the secret `token` is never serialized, and only
 *    client-admins/admins can list invitations, scoped to their own client.
 *  - Client: a non-admin cannot read another client's record (item) and the
 *    collection is scoped to their own client; admins see everything.
 *
 * Follows the ClientAgentApiTest pattern: WebTestCase, JWTs minted via the token
 * manager (no login_check, so the rate-limiter never trips). Builds its own
 * fixtures with a unique per-run suffix and deletes them in tearDown, so it does
 * not depend on seed data and does not pollute the shared dev DB.
 */
final class TenantIsolationApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;

    private Client $clientA;
    private Client $clientB;
    private User $adminUser;
    private User $clientAdminA;
    private User $plainClientA;
    private UserInvitation $invitationA;
    private UserInvitation $invitationB;

    protected function setUp(): void
    {
        // A prior test may have left a kernel booted (we disableReboot below to
        // keep one EntityManager across requests); ensure it's down before
        // createClient() boots a fresh one.
        self::ensureKernelShutdown();

        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);

        $this->suffix = bin2hex(random_bytes(4));

        $this->clientA = $this->makeClient('A');
        $this->clientB = $this->makeClient('B');

        $this->adminUser = $this->makeUser('admin', ['ROLE_ADMIN'], null);
        $this->clientAdminA = $this->makeUser('cadmin-a', ['ROLE_CLIENT_ADMIN'], $this->clientA);
        $this->plainClientA = $this->makeUser('client-a', ['ROLE_CLIENT'], $this->clientA);

        $this->invitationA = $this->makeInvitation($this->clientA);
        $this->invitationB = $this->makeInvitation($this->clientB);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        // Delete in FK-safe order (invitations -> users -> clients), filtered to
        // this run's fixtures so nothing else in the shared DB is touched.
        // Wrapped so a cleanup failure can never skip parent::tearDown() (which
        // shuts the kernel down) — otherwise the next test's createClient() throws.
        try {
            if (isset($this->em) && $this->em->isOpen()) {
                $like = '%-' . $this->suffix;
                $this->em->createQuery('DELETE App\Entity\UserInvitation ui WHERE ui.email LIKE :s')
                    ->setParameter('s', $like)->execute();
                $this->em->createQuery('DELETE App\Entity\User u WHERE u.email LIKE :s')
                    ->setParameter('s', $like)->execute();
                $this->em->createQuery('DELETE App\Entity\Client c WHERE c.code LIKE :s')
                    ->setParameter('s', $like)->execute();
            }
        } finally {
            parent::tearDown();
        }
    }

    // --- UserInvitation -----------------------------------------------------

    public function testPlainClientCannotListInvitations(): void
    {
        $response = $this->get('/api/v1/user_invitations', $this->plainClientA);

        self::assertSame(403, $response['status'], 'A ROLE_CLIENT must not list invitations.');
    }

    public function testClientAdminSeesOnlyOwnClientInvitationsAndNoToken(): void
    {
        $response = $this->get('/api/v1/user_invitations', $this->clientAdminA);

        self::assertSame(200, $response['status']);

        $emails = array_map(static fn (array $m) => $m['email'] ?? null, $response['body']['member'] ?? []);
        self::assertContains($this->invitationA->getEmail(), $emails, 'Client-admin should see their own client\'s invitation.');
        self::assertNotContains($this->invitationB->getEmail(), $emails, 'Client-admin must NOT see another client\'s invitation.');

        foreach ($response['body']['member'] ?? [] as $member) {
            self::assertArrayNotHasKey('token', $member, 'The invitation token must never be serialized.');
        }
    }

    public function testAdminSeesAllInvitationsButStillNoToken(): void
    {
        $response = $this->get('/api/v1/user_invitations', $this->adminUser);

        self::assertSame(200, $response['status']);

        $emails = array_map(static fn (array $m) => $m['email'] ?? null, $response['body']['member'] ?? []);
        self::assertContains($this->invitationA->getEmail(), $emails);
        self::assertContains($this->invitationB->getEmail(), $emails);

        foreach ($response['body']['member'] ?? [] as $member) {
            self::assertArrayNotHasKey('token', $member);
        }
    }

    // --- Client -------------------------------------------------------------

    public function testClientCannotReadAnotherClient(): void
    {
        $response = $this->get('/api/v1/clients/' . $this->clientB->getId(), $this->plainClientA);

        self::assertSame(403, $response['status'], 'A client must not read another client\'s record.');
    }

    public function testClientCanReadOwnClient(): void
    {
        $response = $this->get('/api/v1/clients/' . $this->clientA->getId(), $this->plainClientA);

        self::assertSame(200, $response['status']);
        self::assertSame($this->clientA->getId(), $response['body']['id'] ?? null);
    }

    public function testClientCollectionIsScopedToOwnClient(): void
    {
        $response = $this->get('/api/v1/clients', $this->plainClientA);

        self::assertSame(200, $response['status']);
        $ids = array_map(static fn (array $m) => $m['id'] ?? null, $response['body']['member'] ?? []);
        self::assertContains($this->clientA->getId(), $ids);
        self::assertNotContains($this->clientB->getId(), $ids, 'Client collection must not leak other clients.');
    }

    public function testAdminSeesAllClients(): void
    {
        $response = $this->get('/api/v1/clients', $this->adminUser);

        self::assertSame(200, $response['status']);
        $ids = array_map(static fn (array $m) => $m['id'] ?? null, $response['body']['member'] ?? []);
        self::assertContains($this->clientA->getId(), $ids);
        self::assertContains($this->clientB->getId(), $ids);
    }

    // --- helpers ------------------------------------------------------------

    /** @return array{status:int, body:array<mixed>} */
    private function get(string $url, User $as): array
    {
        $this->client->request('GET', $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $this->tokenFor($as),
            'HTTP_ACCEPT' => 'application/ld+json',
        ]);
        $response = $this->client->getResponse();
        $body = json_decode($response->getContent() ?: 'null', true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
    }

    private function tokenFor(User $user): string
    {
        return self::getContainer()->get(JWTTokenManagerInterface::class)->create($user);
    }

    private function makeClient(string $tag): Client
    {
        $client = (new Client())
            ->setName('Tenant Test ' . $tag . ' ' . $this->suffix)
            ->setCode($tag . '-' . $this->suffix);
        $this->em->persist($client);

        return $client;
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

    private function makeInvitation(Client $client): UserInvitation
    {
        $invitation = new UserInvitation();
        $invitation->setEmail('invite-' . $client->getCode() . '-' . $this->suffix);
        $invitation->setFirstName('Invited');
        $invitation->setLastName('Person');
        $invitation->setClient($client);
        $invitation->setRoles(['ROLE_USER', 'ROLE_CLIENT_ADMIN']);
        $invitation->setCreatedBy($this->adminUser);
        $this->em->persist($invitation);

        return $invitation;
    }
}
