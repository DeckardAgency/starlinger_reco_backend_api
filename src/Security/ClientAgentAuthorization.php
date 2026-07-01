<?php

namespace App\Security;

use App\Entity\Client;
use App\Entity\User;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Single source of truth for "may this user act on behalf of another client?".
 *
 * A user may set `onBehalfOfClient` (on an order) only when:
 *   1. they hold {@see self::ROLE_CLIENT_AGENT}, and
 *   2. their own company is flagged {@see Client::getIsClientAgent()}, and
 *   3. that company manages the target client ({@see Client::managesClient()}).
 *
 * Admins bypass the check.
 */
final class ClientAgentAuthorization
{
    public const ROLE_CLIENT_AGENT = 'ROLE_USER_CLIENT_AGENT';
    public const ROLE_ADMIN = 'ROLE_ADMIN';

    /**
     * Whether the user is even permitted to *attempt* acting on behalf of a
     * client (i.e. is an agent or an admin). Callers use this to decide between
     * validating the request and stripping the on-behalf-of fields entirely.
     */
    public function mayActOnBehalf(User $user): bool
    {
        $roles = $user->getRoles();

        return in_array(self::ROLE_CLIENT_AGENT, $roles, true)
            || in_array(self::ROLE_ADMIN, $roles, true);
    }

    /**
     * Assert the user may act on behalf of $client. A null client is a no-op
     * (nothing is being delegated). Admins are allowed unconditionally.
     *
     * @throws AccessDeniedHttpException when the user is not an authorised agent
     *                                   for the target client.
     */
    public function assertCanActFor(User $user, ?Client $client): void
    {
        if ($client === null) {
            return;
        }

        if (in_array(self::ROLE_ADMIN, $user->getRoles(), true)) {
            return;
        }

        if (!in_array(self::ROLE_CLIENT_AGENT, $user->getRoles(), true)) {
            throw new AccessDeniedHttpException('You are not allowed to act on behalf of other clients.');
        }

        $agentClient = $user->getClient();
        if ($agentClient === null || !$agentClient->getIsClientAgent()) {
            throw new AccessDeniedHttpException('Your company is not configured as a client agent.');
        }

        if (!$agentClient->managesClient($client)) {
            throw new AccessDeniedHttpException(
                sprintf('Your company does not manage client "%s".', $client->getName())
            );
        }
    }

    /**
     * Assert the user may act on behalf of the per-item clients of a collection.
     * Each element is expected to expose getOnBehalfOfClient().
     *
     * @param iterable<object> $items
     */
    public function assertCanActForItems(User $user, iterable $items): void
    {
        foreach ($items as $item) {
            if (method_exists($item, 'getOnBehalfOfClient')) {
                $this->assertCanActFor($user, $item->getOnBehalfOfClient());
            }
        }
    }

    /**
     * Strip every on-behalf-of reference (entity-level and per-item) so a
     * non-agent can never have one persisted — a fail-safe for users who are not
     * permitted to delegate. The $entity must expose set/getOnBehalfOfClient and
     * the $items each expose set/getOnBehalfOfClient.
     *
     * @param iterable<object> $items
     */
    public function stripOnBehalfOf(object $entity, iterable $items): void
    {
        if (method_exists($entity, 'setOnBehalfOfClient')) {
            $entity->setOnBehalfOfClient(null);
        }
        foreach ($items as $item) {
            if (method_exists($item, 'setOnBehalfOfClient')) {
                $item->setOnBehalfOfClient(null);
            }
        }
    }
}
