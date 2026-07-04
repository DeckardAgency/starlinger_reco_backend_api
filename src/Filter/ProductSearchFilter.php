<?php

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\PropertyInfo\Type;

/**
 * OR search filter for products — matches id, name, or partNo.
 * Usage: ?search=query
 */
final class ProductSearchFilter extends AbstractFilter
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

        // If the search value is numeric, also match by ID
        $conditions = sprintf(
            '%1$s.name LIKE :%2$s OR %1$s.partNo LIKE :%2$s',
            $rootAlias, $paramName
        );

        if (ctype_digit($value)) {
            $idParamName = $queryNameGenerator->generateParameterName('searchId');
            $conditions .= sprintf(' OR %s.id = :%s', $rootAlias, $idParamName);
            $queryBuilder->setParameter($idParamName, (int) $value);
        }

        $queryBuilder
            ->andWhere($conditions)
            ->setParameter($paramName, '%' . $value . '%');
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'search' => [
                'property' => null,
                'type' => Type::BUILTIN_TYPE_STRING,
                'required' => false,
                'description' => 'Search products by ID, name, or product code (partNo)',
                'openapi' => [
                    'example' => 'blade',
                ],
            ],
        ];
    }
}
