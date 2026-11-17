<?php

declare(strict_types=1);

namespace App\Tests\Unit\Entity;

use App\Entity\Address;
use App\Entity\Client;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    /**
     * The flag drives the "cannot place orders" warnings in both frontends, so it must
     * ignore billing-only and inactive addresses.
     */
    public function testHasActiveDeliveryAddressNeedsAnActiveDeliveryAddress(): void
    {
        $client = new Client();
        self::assertFalse($client->getHasActiveDeliveryAddress(), 'no addresses');

        $client->addAddress((new Address())->setIsBilling(true)->setIsDelivery(false));
        self::assertFalse($client->getHasActiveDeliveryAddress(), 'billing-only address');

        $client->addAddress((new Address())->setIsDelivery(true)->setIsActive(false));
        self::assertFalse($client->getHasActiveDeliveryAddress(), 'inactive delivery address');

        $client->addAddress((new Address())->setIsDelivery(true));
        self::assertTrue($client->getHasActiveDeliveryAddress(), 'active delivery address');
    }
}
