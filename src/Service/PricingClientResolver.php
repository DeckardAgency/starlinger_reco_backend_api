<?php

namespace App\Service;

use App\Entity\Client;
use App\Entity\User;
use App\Security\ClientAgentAuthorization;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Contracts\Service\ResetInterface;

/**
 * The client whose prices the current request should see.
 *
 * By default that is the logged-in user's own company (guests: none). Any
 * request that serializes products accepts `?forClient=<id>`: the products are
 * then priced as that client would see them — the same prices OrderPriceProcessor
 * applies to an order placed on that client's behalf — so a client agent's shop
 * shows the managed client's prices, not the agent's own.
 *
 * Authorisation is {@see ClientAgentAuthorization::assertCanActFor}: admins may
 * pick any client, agents only clients their company manages, everyone else is
 * refused (403). Naming one's own company is always allowed (same as omitting
 * the parameter). Unknown id → 404, not a positive integer → 400. The result is
 * cached per request (the normalizer asks once per serialized product).
 */
final class PricingClientResolver implements ResetInterface
{
    public const QUERY_PARAMETER = 'forClient';

    /** The main request the cached result belongs to. */
    private ?Request $resolvedFor = null;
    private ?Client $resolved = null;

    public function __construct(
        private readonly RequestStack $requestStack,
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClientAgentAuthorization $agentAuth,
    ) {
    }

    /**
     * @throws BadRequestHttpException   `forClient` is not a positive integer
     * @throws NotFoundHttpException     no client has that id
     * @throws AccessDeniedHttpException the user may not act on behalf of that client
     */
    public function resolve(): ?Client
    {
        $request = $this->requestStack->getMainRequest();
        if ($request === null) {
            // No HTTP request (console, worker): nothing to override.
            return $this->ownClient();
        }

        if ($this->resolvedFor !== $request) {
            $client = $this->resolveFor($request);
            $this->resolvedFor = $request;
            $this->resolved = $client;
        }

        return $this->resolved;
    }

    /** Clears the per-request cache (services resetter, between requests/messages). */
    public function reset(): void
    {
        $this->resolvedFor = null;
        $this->resolved = null;
    }

    private function resolveFor(Request $request): ?Client
    {
        $raw = trim((string) $request->query->get(self::QUERY_PARAMETER, ''));
        if ($raw === '') {
            return $this->ownClient();
        }

        if (!ctype_digit($raw) || (int) $raw < 1) {
            throw new BadRequestHttpException(sprintf('"%s" must be a positive integer client id.', self::QUERY_PARAMETER));
        }
        $clientId = (int) $raw;

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            throw new AccessDeniedHttpException('You are not allowed to act on behalf of other clients.');
        }

        $own = $user->getClient();
        if ($own !== null && $own->getId() === $clientId) {
            return $own;
        }

        $client = $this->entityManager->find(Client::class, $clientId);
        if ($client === null) {
            throw new NotFoundHttpException('Client not found.');
        }

        $this->agentAuth->assertCanActFor($user, $client);

        return $client;
    }

    private function ownClient(): ?Client
    {
        $user = $this->security->getUser();

        return $user instanceof User ? $user->getClient() : null;
    }
}
