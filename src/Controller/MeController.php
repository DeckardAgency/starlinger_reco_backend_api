<?php

namespace App\Controller;

use App\Entity\User;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Returns the authenticated user's profile. Needed because the access token now lives
 * in an HttpOnly cookie the frontend cannot read — the SPA calls this on login and on
 * app bootstrap to hydrate the current user (identity + roles + client) instead of
 * decoding the JWT client-side.
 */
class MeController extends AbstractController
{
    public function __construct(private readonly Security $security)
    {
    }

    #[Route('/api/me', name: 'api_me', methods: ['GET'])]
    public function me(): JsonResponse
    {
        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return $this->json(['message' => 'Not authenticated'], 401);
        }

        $client = $user->getClient();

        return $this->json([
            'id' => $user->getId(),
            'email' => $user->getEmail(),
            'username' => $user->getUsername(),
            'firstName' => $user->getFirstName(),
            'lastName' => $user->getLastName(),
            'roles' => $user->getRoles(),
            'client' => $client === null ? null : [
                'id' => $client->getId(),
                'name' => $client->getName(),
                'code' => $client->getCode(),
                'isActive' => $client->getIsActive(),
                'isArchived' => $client->getIsArchived(),
            ],
        ]);
    }
}
