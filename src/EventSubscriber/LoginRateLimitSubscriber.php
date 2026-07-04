<?php

namespace App\EventSubscriber;

use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Throttles the API surface in two passes:
 *
 *  1. onAuthSensitiveRequest (priority 10, BEFORE the security firewall) — tight limits
 *     on unauthenticated auth endpoints, keyed on data an attacker can't fake cheaply:
 *       - /api/login_check          : per-IP AND per-username (credential stuffing)
 *       - /api/auth/forgot-password : per-IP (email bombing / enumeration)
 *       - /api/auth/reset-password  : per-IP (token probing)
 *     Running before authentication means brute force is blocked before credentials are
 *     ever checked.
 *
 *  2. onApiRequest (priority 7, AFTER the firewall) — a generous baseline abuse limit on
 *     every /api request, keyed on the *authenticated* user identity (falling back to IP
 *     for anonymous traffic). Keying on the validated identity — rather than the raw
 *     token — is deliberate: a forged/garbage token is rejected by the firewall and never
 *     reaches this listener, so an attacker cannot mint fresh buckets by rotating fake
 *     tokens, and genuine users behind a shared egress (e.g. server-side rendering) are
 *     still counted individually rather than collectively.
 */
class LoginRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RateLimiterFactory $loginLimiter,
        private RateLimiterFactory $loginUsernameLimiter,
        private RateLimiterFactory $sensitiveOperationsLimiter,
        private RateLimiterFactory $apiLimiter,
        private Security $security
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        // The security firewall authenticates on kernel.request at priority 8, so the
        // auth-sensitive pass sits above it (10) and the baseline pass sits below it (7).
        return [
            KernelEvents::REQUEST => [
                ['onAuthSensitiveRequest', 10],
                ['onApiRequest', 7],
            ],
        ];
    }

    public function onAuthSensitiveRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        $path = $request->getPathInfo();
        $ip = $request->getClientIp() ?? 'unknown';

        if ($path === '/api/login_check') {
            $this->enforce($this->loginLimiter->create($ip));

            $content = json_decode($request->getContent(), true);
            $username = is_array($content) ? ($content['username'] ?? null) : null;
            if (is_string($username) && $username !== '') {
                $this->enforce($this->loginUsernameLimiter->create('login_' . strtolower($username)));
            }
            return;
        }

        if ($path === '/api/auth/forgot-password') {
            $this->enforce($this->sensitiveOperationsLimiter->create('forgot_' . $ip));
            return;
        }

        if ($path === '/api/auth/reset-password') {
            $this->enforce($this->sensitiveOperationsLimiter->create('reset_' . $ip));
        }
    }

    public function onApiRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();
        if (!str_starts_with($request->getPathInfo(), '/api')) {
            return;
        }

        // Prefer the firewall-validated identity; anonymous traffic falls back to IP.
        $user = $this->security->getUser();
        $key = $user !== null
            ? 'user_' . $user->getUserIdentifier()
            : 'ip_' . ($request->getClientIp() ?? 'unknown');

        $this->enforce($this->apiLimiter->create('api_' . $key));
    }

    private function enforce(LimiterInterface $limiter): void
    {
        $limit = $limiter->consume(1);
        if (!$limit->isAccepted()) {
            throw new TooManyRequestsHttpException(
                $limit->getRetryAfter()->getTimestamp() - time(),
                'Too many attempts. Please try again later.'
            );
        }
    }
}
