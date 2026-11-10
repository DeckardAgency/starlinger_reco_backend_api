<?php

declare(strict_types=1);

namespace App\Tests\Unit\Security;

use App\Security\Roles;
use PHPUnit\Framework\TestCase;

final class RolesTest extends TestCase
{
    public function testRoleUserAloneIsNotFunctional(): void
    {
        self::assertFalse(Roles::hasFunctional([]));
        self::assertFalse(Roles::hasFunctional(['ROLE_USER']));
        foreach (Roles::FUNCTIONAL as $role) {
            self::assertTrue(Roles::hasFunctional(['ROLE_USER', $role]), $role);
        }
    }

    public function testWithFunctionalDefaultAddsClientOnlyWhenNeeded(): void
    {
        self::assertSame(['ROLE_USER', 'ROLE_CLIENT'], Roles::withFunctionalDefault(['ROLE_USER']));
        self::assertSame(['ROLE_CLIENT'], Roles::withFunctionalDefault([]));
        self::assertSame(['ROLE_FINANCE'], Roles::withFunctionalDefault(['ROLE_FINANCE']));
        self::assertSame(['ROLE_USER', 'ROLE_CLIENT_ADMIN'], Roles::withFunctionalDefault(['ROLE_USER', 'ROLE_CLIENT_ADMIN']));
    }
}
