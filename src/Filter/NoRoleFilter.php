<?php

declare(strict_types=1);

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Security\Roles;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\PropertyInfo\Type;

/**
 * `?hasRole=false` lists users whose stored roles contain none of Roles::FUNCTIONAL —
 * accounts that can sign in but cannot use the webshop — so an admin can find them
 * without scrolling the whole list. `?hasRole=true` is the complement.
 */
final class NoRoleFilter extends AbstractFilter
{
    protected function filterProperty(
        string $property,
        $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        ?Operation $operation = null,
        array $context = []
    ): void {
        if ($property !== 'hasRole') {
            return;
        }
        $hasRole = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($hasRole === null) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        // roles is a JSON array column; a substring match per functional role is enough
        // (ROLE_CLIENT also matches ROLE_CLIENT_ADMIN, which is functional too).
        $likes = [];
        foreach (Roles::FUNCTIONAL as $i => $role) {
            $param = $queryNameGenerator->generateParameterName('functionalRole');
            $likes[] = sprintf('%s.roles LIKE :%s', $alias, $param);
            $queryBuilder->setParameter($param, '%' . $role . '%');
        }
        $anyFunctional = '(' . implode(' OR ', $likes) . ')';

        $queryBuilder->andWhere($hasRole ? $anyFunctional : 'NOT ' . $anyFunctional);
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'hasRole' => [
                'property' => 'hasRole',
                'type' => Type::BUILTIN_TYPE_BOOL,
                'required' => false,
                'description' => 'false: users without any functional role (can sign in, cannot use the webshop); true: users with one',
                'openapi' => ['example' => 'false', 'allowReserved' => false, 'allowEmptyValue' => false, 'explode' => false],
            ],
        ];
    }
}
