<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Entity\Client;
use App\Entity\User;
use App\Security\ClientAgentAuthorization;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Pure-logic unit tests for the single source of truth that decides whether a
 * user may place orders on behalf of another client.
 */
final class ClientAgentAuthorizationTest extends TestCase
{
    private ClientAgentAuthorization $auth;

    protected function setUp(): void
    {
        $this->auth = new ClientAgentAuthorization();
    }

    // --- mayActOnBehalf -----------------------------------------------------

    public function testAgentMayActOnBehalf(): void
    {
        self::assertTrue($this->auth->mayActOnBehalf($this->user(['ROLE_USER_CLIENT_AGENT'])));
    }

    public function testAdminMayActOnBehalf(): void
    {
        self::assertTrue($this->auth->mayActOnBehalf($this->user(['ROLE_ADMIN'])));
    }

    public function testPlainClientMayNotActOnBehalf(): void
    {
        self::assertFalse($this->auth->mayActOnBehalf($this->user(['ROLE_CLIENT'])));
    }

    // --- assertCanActFor ----------------------------------------------------

    public function testNullClientIsANoOp(): void
    {
        $this->expectNotToPerformAssertions();
        // A plain client with no delegation target must pass untouched.
        $this->auth->assertCanActFor($this->user(['ROLE_CLIENT']), null);
    }

    public function testAdminBypassesEvenForUnmanagedClient(): void
    {
        $this->expectNotToPerformAssertions();
        $target = $this->client(isAgent: false);
        $this->auth->assertCanActFor($this->user(['ROLE_ADMIN']), $target);
    }

    public function testNonAgentCannotActForAClient(): void
    {
        $this->expectException(AccessDeniedHttpException::class);
        $this->auth->assertCanActFor($this->user(['ROLE_CLIENT']), $this->client(isAgent: false));
    }

    public function testAgentWhoseCompanyIsNotFlaggedIsRejected(): void
    {
        $company = $this->client(isAgent: false); // company NOT flagged as agent
        $user = $this->user(['ROLE_USER_CLIENT_AGENT'], $company);

        $this->expectException(AccessDeniedHttpException::class);
        $this->auth->assertCanActFor($user, $this->client(isAgent: false));
    }

    public function testAgentCannotActForUnmanagedClient(): void
    {
        $company = $this->client(isAgent: true); // agent, but manages nobody
        $user = $this->user(['ROLE_USER_CLIENT_AGENT'], $company);

        $this->expectException(AccessDeniedHttpException::class);
        $this->auth->assertCanActFor($user, $this->client(isAgent: false));
    }

    public function testAgentCanActForManagedClient(): void
    {
        $this->expectNotToPerformAssertions();
        $managed = $this->client(isAgent: false);
        $company = $this->client(isAgent: true, managed: [$managed]);
        $user = $this->user(['ROLE_USER_CLIENT_AGENT'], $company);

        $this->auth->assertCanActFor($user, $managed);
    }

    // --- assertCanActForItems ----------------------------------------------

    public function testItemsAllManagedPass(): void
    {
        $this->expectNotToPerformAssertions();
        $a = $this->client(isAgent: false);
        $b = $this->client(isAgent: false);
        $company = $this->client(isAgent: true, managed: [$a, $b]);
        $user = $this->user(['ROLE_USER_CLIENT_AGENT'], $company);

        $this->auth->assertCanActForItems($user, [$this->item($a), $this->item($b)]);
    }

    public function testItemsRejectedWhenOneIsUnmanaged(): void
    {
        $managed = $this->client(isAgent: false);
        $unmanaged = $this->client(isAgent: false);
        $company = $this->client(isAgent: true, managed: [$managed]);
        $user = $this->user(['ROLE_USER_CLIENT_AGENT'], $company);

        $this->expectException(AccessDeniedHttpException::class);
        $this->auth->assertCanActForItems($user, [$this->item($managed), $this->item($unmanaged)]);
    }

    public function testItemsWithoutDelegationAreIgnored(): void
    {
        $this->expectNotToPerformAssertions();
        $user = $this->user(['ROLE_CLIENT']);
        // null per-item client => no-op, even for a non-agent.
        $this->auth->assertCanActForItems($user, [$this->item(null)]);
    }

    // --- stripOnBehalfOf ----------------------------------------------------

    public function testStripNullsEntityAndEveryItem(): void
    {
        $target = $this->client(isAgent: false);
        $entity = $this->item($target);
        $items = [$this->item($target), $this->item($target)];

        $this->auth->stripOnBehalfOf($entity, $items);

        self::assertNull($entity->getOnBehalfOfClient());
        foreach ($items as $item) {
            self::assertNull($item->getOnBehalfOfClient());
        }
    }

    // --- helpers ------------------------------------------------------------

    /** @param string[] $roles */
    private function user(array $roles, ?Client $client = null): User
    {
        $user = new User();
        $user->setRoles($roles);
        if ($client !== null) {
            $user->setClient($client);
        }
        return $user;
    }

    /** @param Client[] $managed */
    private function client(bool $isAgent, array $managed = []): Client
    {
        $client = new Client();
        $client->setName('Test Client');
        $client->setIsClientAgent($isAgent);
        foreach ($managed as $m) {
            $client->addManagedClient($m);
        }
        return $client;
    }

    /** A minimal item exposing get/setOnBehalfOfClient (Order/OrderItem shape). */
    private function item(?Client $client): object
    {
        $item = new class {
            private ?Client $onBehalfOfClient = null;
            public function getOnBehalfOfClient(): ?Client
            {
                return $this->onBehalfOfClient;
            }
            public function setOnBehalfOfClient(?Client $c): void
            {
                $this->onBehalfOfClient = $c;
            }
        };
        $item->setOnBehalfOfClient($client);
        return $item;
    }
}
