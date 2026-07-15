<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Address;
use App\Entity\Client;
use App\Entity\ClientProductPrice;
use App\Entity\SupportTicket;
use App\Entity\User;
use App\Entity\UserInvitation;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Scopes collection and item queries to the authenticated user's client, so a
 * non-admin can only read/modify rows belonging to their own client. Item
 * operations are scoped too, so PUT/PATCH/DELETE on another client's row yields
 * a 404. Admins bypass entirely. (Orders are handled by OrderClientExtension.)
 */
final class ClientOwnershipExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(private readonly Security $security)
    {
    }

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        $this->addWhere($queryBuilder, $resourceClass, true);
    }

    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = []
    ): void {
        $this->addWhere($queryBuilder, $resourceClass, false);
    }

    private function addWhere(QueryBuilder $queryBuilder, string $resourceClass, bool $isCollection): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        // Admins see everything.
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return;
        }

        $clientId = $user->getClient()?->getId();
        $alias = $queryBuilder->getRootAliases()[0];

        switch ($resourceClass) {
            case Address::class:
            case ClientProductPrice::class:
            case User::class:
            case UserInvitation::class:
                // Direct client relation. A client-admin sees only their own
                // client's invitations; the GetCollection/Get security
                // expression already blocks plain ROLE_CLIENT/ROLE_USER.
                if ($clientId === null) {
                    $queryBuilder->andWhere('1 = 0');
                    return;
                }
                $queryBuilder
                    ->andWhere(sprintf('IDENTITY(%s.client) = :co_client_id', $alias))
                    ->setParameter('co_client_id', $clientId);
                break;

            case Client::class:
                // Scope the COLLECTION only, to the caller's own client row.
                // Item queries are intentionally left unscoped here and gated by
                // the Get operation's `security` expression instead: agent
                // "order on behalf of" resolves managed-client IRIs through the
                // item provider (which runs these extensions but NOT operation
                // security), so scoping items would break that third-party
                // client-agent feature.
                if (!$isCollection) {
                    return;
                }
                if ($clientId === null) {
                    $queryBuilder->andWhere('1 = 0');
                    return;
                }
                $queryBuilder
                    ->andWhere(sprintf('%s.id = :co_client_id', $alias))
                    ->setParameter('co_client_id', $clientId);
                break;

            case SupportTicket::class:
                // Scoped via the ticket's owner -> owner.client.
                if ($clientId === null) {
                    $queryBuilder
                        ->andWhere(sprintf('%s.user = :co_user', $alias))
                        ->setParameter('co_user', $user);
                    return;
                }
                $queryBuilder
                    ->join(sprintf('%s.user', $alias), 'co_user')
                    ->andWhere('IDENTITY(co_user.client) = :co_client_id')
                    ->setParameter('co_client_id', $clientId);
                break;
        }
    }
}
