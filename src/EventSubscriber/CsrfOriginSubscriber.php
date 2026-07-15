<?php

namespace App\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * CSRF defence for cookie-authenticated writes.
 *
 * The auth cookie is issued with SameSite=None (frontend and API are on
 * different origins), so the browser attaches it to cross-site requests. A
 * multipart/form-data POST is a CORS "simple" request and triggers no preflight,
 * meaning an auto-submitting attacker form could drive a state change as the
 * victim. To close that, every unsafe-method request that carries the BEARER
 * auth cookie must present an Origin the CORS config already trusts.
 *
 * Scope is deliberately narrow to avoid breaking legitimate traffic:
 *  - Safe methods (GET/HEAD/OPTIONS) are never checked.
 *  - Requests without the BEARER cookie are skipped — pure API clients that
 *    authenticate with the Authorization header are not CSRF-able, and the
 *    login/refresh endpoints have no BEARER cookie yet.
 *  - Only enforced when an Origin header is present. Browsers always send Origin
 *    on cross-site POST/PUT/PATCH/DELETE (the actual attack vector), so a
 *    mismatching Origin is rejected while same-origin requests that omit it pass.
 */
final class CsrfOriginSubscriber implements EventSubscriberInterface
{
    private const AUTH_COOKIE = 'BEARER';
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function __construct(private readonly string $allowedOriginRegex)
    {
    }

    public static function getSubscribedEvents(): array
    {
        // Priority 9 so it runs after Symfony's Firewall/router but before the
        // controller; value is not critical as long as it is a main request.
        return [KernelEvents::REQUEST => ['onKernelRequest', 9]];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return;
        }

        if (!$request->cookies->has(self::AUTH_COOKIE)) {
            return;
        }

        $origin = $request->headers->get('Origin');
        if ($origin === null || $origin === '') {
            return;
        }

        if ($this->allowedOriginRegex === '' || !preg_match('#' . $this->allowedOriginRegex . '#i', $origin)) {
            throw new AccessDeniedHttpException('Cross-origin state-changing request rejected.');
        }
    }
}
