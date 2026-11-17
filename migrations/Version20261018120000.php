<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Multi-client checkout (Oct 2026): `POST /orders/checkout` turns a client
 * agent's mixed cart into one order per managed client, all in one transaction.
 * Every order of such a call carries the same server-generated
 * order.checkout_reference ("CHK-YYYYMMDD-XXXXXX"), indexed so the orders of
 * one checkout can be listed together (`GET /orders?checkoutReference=…`).
 * Nullable: orders placed any other way keep NULL.
 */
final class Version20261018120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Order checkout_reference (groups the orders of one multi-client checkout) + index';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order` ADD checkout_reference VARCHAR(40) DEFAULT NULL');
        $this->addSql('CREATE INDEX idx_order_checkout_reference ON `order` (checkout_reference)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_order_checkout_reference ON `order`');
        $this->addSql('ALTER TABLE `order` DROP checkout_reference');
    }
}
