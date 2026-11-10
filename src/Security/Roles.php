<?php

declare(strict_types=1);

namespace App\Security;

/**
 * Role vocabulary. ROLE_USER only means "authenticated": a user whose stored roles
 * contain none of the FUNCTIONAL roles can log in but cannot do anything (no cart,
 * no orders, no notifications), so such accounts must never be created.
 */
final class Roles
{
    public const USER = 'ROLE_USER';
    public const CLIENT = 'ROLE_CLIENT';
    public const CLIENT_ADMIN = 'ROLE_CLIENT_ADMIN';
    public const CLIENT_AGENT = 'ROLE_USER_CLIENT_AGENT';
    public const FINANCE = 'ROLE_FINANCE';
    public const ADMIN = 'ROLE_ADMIN';

    /** Roles that grant an actual capability (ordering, administration, notifications). */
    public const FUNCTIONAL = [
        self::CLIENT,
        self::CLIENT_ADMIN,
        self::CLIENT_AGENT,
        self::FINANCE,
        self::ADMIN,
    ];

    /** @param string[] $roles */
    public static function hasFunctional(array $roles): bool
    {
        return array_intersect($roles, self::FUNCTIONAL) !== [];
    }

    /**
     * Ensures a role set is usable: adds ROLE_CLIENT when no functional role is present.
     *
     * @param string[] $roles
     * @return string[]
     */
    public static function withFunctionalDefault(array $roles): array
    {
        return self::hasFunctional($roles) ? $roles : array_values(array_unique([...$roles, self::CLIENT]));
    }

    private function __construct()
    {
    }
}
