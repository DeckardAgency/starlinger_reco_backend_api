<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260426141908 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SRECO-25 + SRECO-17: add product.product_type, discount.product_types (JSON), order.shipping_address_id';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE discount ADD product_types JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE `order` ADD shipping_address_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD product_type VARCHAR(10) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE discount DROP product_types');
        $this->addSql('ALTER TABLE `order` DROP shipping_address_id');
        $this->addSql('ALTER TABLE product DROP product_type');
    }
}
