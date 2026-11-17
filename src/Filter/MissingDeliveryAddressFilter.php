<?php

declare(strict_types=1);

namespace App\Filter;

use ApiPlatform\Doctrine\Orm\Filter\AbstractFilter;
use ApiPlatform\Doctrine\Orm\Util\QueryNameGeneratorInterface;
use ApiPlatform\Metadata\Operation;
use App\Entity\Address;
use Doctrine\ORM\QueryBuilder;
use Symfony\Component\PropertyInfo\Type;

/**
 * `?hasActiveDeliveryAddress=false` lists clients without an active delivery address
 * (their users cannot place orders) — the query-side twin of
 * Client::getHasActiveDeliveryAddress(), so the admin can filter instead of scrolling.
 */
final class MissingDeliveryAddressFilter extends AbstractFilter
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
        if ($property !== 'hasActiveDeliveryAddress') {
            return;
        }
        $has = filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
        if ($has === null) {
            return;
        }

        $alias = $queryBuilder->getRootAliases()[0];
        $addr = $queryNameGenerator->generateJoinAlias('deliveryAddress');
        $exists = sprintf(
            'EXISTS (SELECT %1$s.id FROM %2$s %1$s WHERE %1$s.client = %3$s AND %1$s.isActive = true AND %1$s.isDelivery = true)',
            $addr,
            Address::class,
            $alias
        );

        $queryBuilder->andWhere($has ? $exists : 'NOT ' . $exists);
    }

    public function getDescription(string $resourceClass): array
    {
        return [
            'hasActiveDeliveryAddress' => [
                'property' => 'hasActiveDeliveryAddress',
                'type' => Type::BUILTIN_TYPE_BOOL,
                'required' => false,
                'description' => 'false: clients without an active delivery address (users cannot order); true: clients with one',
                'openapi' => ['example' => 'false', 'allowReserved' => false, 'allowEmptyValue' => false, 'explode' => false],
            ],
        ];
    }
}
