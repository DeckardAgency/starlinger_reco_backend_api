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
 * A user with ROLE_USER only can log in but do nothing (no cart, no orders, no error),
 * so the API must refuse to create one and invitations must default to a real role.
 */
final class UserFunctionalRoleApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;
    private Client $company;
    private User $admin;

    protected function setUp(): void
    {
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = bin2hex(random_bytes(4));

        $this->company = (new Client())
            ->setName('Role Test ' . $this->suffix)
            ->setCode('RT-' . $this->suffix);
        $this->em->persist($this->company);
        $this->admin = (new User())
            ->setEmail('admin-' . $this->suffix . '@example.test')
            ->setFirstName('Test')
            ->setLastName('Admin');
        $this->admin->setPassword('not-used-in-tests');
        $this->admin->setRoles(['ROLE_ADMIN']);
        $this->em->persist($this->admin);
        $this->em->flush();
    }

    protected function tearDown(): void
    {
        try {
            if (isset($this->em) && $this->em->isOpen()) {
                $like = '%-' . $this->suffix . '@example.test';
                $this->em->createQuery('DELETE App\Entity\UserInvitation i WHERE i.email LIKE :s')
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

    public function testCreatingAUserWithoutAFunctionalRoleIsRejected(): void
    {
        foreach ([[], ['ROLE_USER']] as $roles) {
            $response = $this->post('/api/v1/users', $this->userPayload('dead', $roles));

            self::assertSame(422, $response['status'], json_encode($roles));
            $paths = array_column($response['body']['violations'] ?? [], 'propertyPath');
            self::assertContains('roles', $paths, 'violation must point at roles');
        }
    }

    public function testCreatingAUserWithAFunctionalRoleWorks(): void
    {
        $response = $this->post('/api/v1/users', $this->userPayload('alive', ['ROLE_CLIENT']));

        self::assertSame(201, $response['status'], json_encode($response['body']));
        self::assertContains('ROLE_CLIENT', $response['body']['roles'] ?? []);
    }

    public function testInvitationWithoutRolesDefaultsToClient(): void
    {
        $email = 'invitee-' . $this->suffix . '@example.test';
        $response = $this->post('/api/v1/user_invitations', [
            'email' => $email,
            'firstName' => 'In',
            'lastName' => 'Vitee',
            'client' => '/api/v1/clients/' . $this->company->getId(),
        ]);

        self::assertSame(201, $response['status'], json_encode($response['body']));
        $invitation = $this->em->getRepository(UserInvitation::class)->findOneBy(['email' => $email]);
        self::assertNotNull($invitation);
        self::assertContains('ROLE_CLIENT', $invitation->getRoles(), 'ROLE_USER-only invitations used to create dead accounts');
    }

    // --- helpers ------------------------------------------------------------

    /** @return array<string,mixed> */
    private function userPayload(string $tag, array $roles): array
    {
        return [
            'email' => $tag . '-' . $this->suffix . '@example.test',
            'plainPassword' => 'Recouser123!',
            'firstName' => 'Test',
            'lastName' => ucfirst($tag),
            'roles' => $roles,
            'client' => '/api/v1/clients/' . $this->company->getId(),
        ];
    }

    /** @return array{status:int, body:array<mixed>} */
    private function post(string $url, array $json): array
    {
        $token = self::getContainer()->get(JWTTokenManagerInterface::class)->create($this->admin);
        $this->client->request('POST', $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . $token,
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => 'application/ld+json',
        ], json_encode($json, JSON_THROW_ON_ERROR));
        $response = $this->client->getResponse();
        $body = json_decode($response->getContent() ?: 'null', true);

        return ['status' => $response->getStatusCode(), 'body' => is_array($body) ? $body : []];
    }
}
