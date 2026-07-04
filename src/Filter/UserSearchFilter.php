<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\PropertyInfo\Type;

/**
 * OR search filter for users — matches email OR firstName OR lastName.
 * Usage: ?search=query
 */
final class UserSearchFilter extends AbstractFilter
{
    protected function filterProperty(
        string $property,
               $value,
        QueryBuilder $queryBuilder,
        QueryNameGeneratorInterface $queryNameGenerator,
        string $resourceClass,
        Operation $operation = null,
        array $context = []
    ): void {
        if ($property !== 'search') {
            return;
        }

        $value = trim($value);
        if ($value === '') {
            return;
        }

        $rootAlias = $queryBuilder->getRootAliases()[0];
        $paramName = $queryNameGenerator->generateParameterName('search');

        $queryBuilder
            ->andWhere(sprintf(
                '%1$s.email LIKE :%2$s OR %1$s.firstName LIKE :%2$s OR %1$s.lastName LIKE :%2$s',
                $rootAlias, $paramName
            ))
            ->setParameter($paramName, '%' . $value . '%');
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'search' => [
                'property' => null,
                'type' => Type::BUILTIN_TYPE_STRING,
                'required' => false,
                'description' => 'Search users by email, first name, or last name',
                'openapi' => [
                    'example' => 'john',
                ],
            ],
        ];
    }
}
