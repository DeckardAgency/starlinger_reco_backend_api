<?php

namespace App\EventSubscriber;

use App\Entity\User;
use App\Repository\UserRepository;
use App\Service\LoginAttemptService;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationFailureEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Event\AuthenticationSuccessEvent;
use Lexik\Bundle\JWTAuthenticationBundle\Events;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException;

class AuthenticationSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private LoginAttemptService $loginAttemptService,
        private UserRepository $userRepository,
        private RequestStack $requestStack
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            Events::AUTHENTICATION_SUCCESS => 'onAuthenticationSuccess',
            Events::AUTHENTICATION_FAILURE => 'onAuthenticationFailure',
        ];
    }

    public function onAuthenticationSuccess(AuthenticationSuccessEvent $event): void
    {
        $user = $event->getUser();

        if (!$user instanceof User) {
            return;
        }

        // Reset failed login attempts on successful login
        $this->loginAttemptService->recordSuccessfulLogin($user);
    }

    public function onAuthenticationFailure(AuthenticationFailureEvent $event): void
    {
        // Maintain the lockout counter server-side, but NEVER reveal account existence,
        // remaining-attempt counts, or lock state in the response — those differences let
        // an attacker enumerate valid accounts and read per-account lock state. Every
        // failure returns the same generic 401 that Lexik returns for unknown users.
        $request = $this->requestStack->getCurrentRequest();
        if ($request) {
            $content = json_decode($request->getContent(), true);
            $username = is_array($content) ? ($content['username'] ?? null) : null;

            if ($username) {
                $user = $this->userRepository->findOneBy(['email' => $username]);
                if ($user instanceof User && !$this->loginAttemptService->checkAccountLock($user)['locked']) {
                    $this->loginAttemptService->recordFailedAttempt($user);
                }
            }
        }

        $event->setResponse(new JsonResponse([
            'code' => 401,
            'message' => 'Invalid credentials.',
        ], 401));
    }
}
