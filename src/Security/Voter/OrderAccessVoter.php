<?php

namespace App\Security\Voter;

use App\Entity\Order;
use App\Entity\OrderInfoMessage;
use App\Entity\OrderInfoRequest;
use App\Entity\OrderItem;
use App\Entity\User;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\Voter\Voter;

/**
 * Tenant-ownership check for the Order family, used on write operations via
 * securityPostDenormalize. After denormalization the submitted object carries its
 * linked order (directly or through orderRef / infoRequest), so this catches
 * cross-tenant create/attach that the read-side Doctrine extension cannot see
 * (there is no query on a plain POST). Admins pass; a user with a client must match
 * the owning order's client; a user without a client must be the order's own user.
 */
class OrderAccessVoter extends Voter
{
    public const OWN = 'OWN_ORDER';

    protected function supports(string $attribute, mixed $subject): bool
    {
        return $attribute === self::OWN
            && ($subject instanceof Order
                || $subject instanceof OrderItem
                || $subject instanceof OrderInfoRequest
                || $subject instanceof OrderInfoMessage);
    }

    protected function voteOnAttribute(string $attribute, mixed $subject, TokenInterface $token): bool
    {
        $user = $token->getUser();
        if (!$user instanceof User) {
            return false;
        }

        if (in_array('ROLE_ADMIN', $user->getRoles(), true)) {
            return true;
        }

        $owner = $this->resolveOwner($subject);
        if (!$owner instanceof User) {
            // Cannot establish ownership => deny for non-admins.
            return false;
        }

        $userClient = $user->getClient();
        if ($userClient !== null) {
            return $owner->getClient()?->getId() === $userClient->getId();
        }

        // No client: only the user's own order family.
        return $owner->getId() === $user->getId();
    }

    private function resolveOwner(mixed $subject): ?User
    {
        return match (true) {
            $subject instanceof Order => $subject->getUser(),
            $subject instanceof OrderItem => $subject->getOrderRef()?->getUser(),
            $subject instanceof OrderInfoRequest => $subject->getOrder()?->getUser(),
            $subject instanceof OrderInfoMessage => $subject->getInfoRequest()?->getOrder()?->getUser(),
            default => null,
        };
    }
}
