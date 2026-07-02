<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\RateLimiter\LimiterInterface;
use Symfony\Component\RateLimiter\RateLimiterFactory;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

/**
 * Throttles authentication-sensitive endpoints:
 *  - /api/login_check          : per-IP AND per-username (credential stuffing across rotating IPs)
 *  - /api/auth/forgot-password : per-IP (email bombing / enumeration attempts)
 */
class LoginRateLimitSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private RateLimiterFactory $loginLimiter,
        private RateLimiterFactory $loginUsernameLimiter,
        private RateLimiterFactory $sensitiveOperationsLimiter
    ) {
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 10],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
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
        }
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
