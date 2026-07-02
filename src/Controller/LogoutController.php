<?php

namespace App\Controller;

use App\Entity\User;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

/**
 * Stateless-JWT logout: clears the HttpOnly access + refresh cookies and revokes the
 * user's stored refresh tokens so a stolen refresh token cannot be reused after logout.
 */
class LogoutController extends AbstractController
{
    public function __construct(
        private readonly Security $security,
        private readonly EntityManagerInterface $entityManager,
        #[Autowire('%env(bool:JWT_COOKIE_SECURE)%')] private readonly bool $cookieSecure
    ) {
    }

    #[Route('/api/logout', name: 'api_logout', methods: ['POST'])]
    public function logout(): JsonResponse
    {
        $user = $this->security->getUser();
        if ($user instanceof User) {
            $this->entityManager->createQuery(
                'DELETE FROM App\Entity\RefreshToken r WHERE r.username = :username'
            )->setParameter('username', $user->getUserIdentifier())->execute();
        }

        $response = new JsonResponse(['success' => true]);
        // Clear both cookies (paths must match how they were set).
        $response->headers->setCookie(Cookie::create('BEARER', '', 1, '/', null, $this->cookieSecure, true, false, Cookie::SAMESITE_LAX));
        $response->headers->setCookie(Cookie::create('refresh_token', '', 1, '/api/token/refresh', null, $this->cookieSecure, true, false, Cookie::SAMESITE_LAX));

        return $response;
    }
}
