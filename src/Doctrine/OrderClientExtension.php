<?php

namespace App\Doctrine;

use ApiPlatform\Doctrine\Orm\Extension\QueryCollectionExtensionInterface;
use ApiPlatform\Doctrine\Orm\Extension\QueryItemExtensionInterface;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Order;
use App\Entity\OrderInfoMessage;
use App\Entity\OrderInfoRequest;
use App\Entity\OrderItem;
use App\Entity\User;
use Doctrine\ORM\QueryBuilder;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Scopes the Order family of resources (Order, OrderItem, OrderInfoRequest,
 * OrderInfoMessage) to the authenticated user's client, so a non-admin can only
 * read/modify rows belonging to their own client. Item operations are scoped too,
 * so GET/PUT/PATCH/DELETE on another client's row yields a 404.
 *
 * A user without a client is restricted to their OWN records (never everyone's) —
 * this mirrors the null-client handling in ClientOwnershipExtension and closes the
 * cross-tenant leak that a bare `return` here previously allowed. Admins bypass.
 *
 * Note: this guards reads and item lookups. Cross-tenant *create/attach* on write
 * (e.g. POSTing an OrderItem onto another client's order) is blocked by
 * OrderAccessVoter via securityPostDenormalize on the write operations.
 */
final class OrderClientExtension implements QueryCollectionExtensionInterface, QueryItemExtensionInterface
{
    public function __construct(
        private Security $security
    ) {}

    public function applyToCollection(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        $this->addWhere($queryBuilder, $resourceClass);
    }

    public function applyToItem(
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        array $identifiers,
        ?Operation $operation = null,
        array $context = []
    ): void {
        $this->addWhere($queryBuilder, $resourceClass);
    }

    private function addWhere(QueryBuilder $queryBuilder, string $resourceClass): void
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        // Admins see everything.
        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $userAlias = 'oce_u';

        // Build the join chain from the resource down to the owning order's user.
        switch ($resourceClass) {
            case Order::class:
                $queryBuilder->join(sprintf('%s.user', $rootAlias), $userAlias);
                break;

            case OrderItem::class:
                $queryBuilder
                    ->join(sprintf('%s.orderRef', $rootAlias), 'oce_o')
                    ->join('oce_o.user', $userAlias);
                break;

            case OrderInfoRequest::class:
                $queryBuilder
                    ->join(sprintf('%s.order', $rootAlias), 'oce_o')
                    ->join('oce_o.user', $userAlias);
                break;

            case OrderInfoMessage::class:
                $queryBuilder
                    ->join(sprintf('%s.infoRequest', $rootAlias), 'oce_ir')
                    ->join('oce_ir.order', 'oce_o')
                    ->join('oce_o.user', $userAlias);
                break;

            default:
                return;
        }

        $clientId = $user->getClient()?->getId();

        if ($clientId !== null) {
            $queryBuilder
                ->andWhere(sprintf('IDENTITY(%s.client) = :oce_client_id', $userAlias))
                ->setParameter('oce_client_id', $clientId);
        } else {
            // No client => only the user's own records, never all tenants'.
            $queryBuilder
                ->andWhere(sprintf('%s.id = :oce_user_id', $userAlias))
                ->setParameter('oce_user_id', $user->getId());
        }
    }
}
