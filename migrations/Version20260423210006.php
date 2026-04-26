<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260423210006 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Master data cleanup: drop unused fields on country, delivery_type, payment_type, product, tax_type; make country.tax_type_id required.';
    }

    public function up(Schema $schema): void
    {
        // Safety: ensure every country has a tax_type before making the column NOT NULL
        $this->addSql('UPDATE country SET tax_type_id = (SELECT id FROM tax_type ORDER BY id ASC LIMIT 1) WHERE tax_type_id IS NULL');

        $this->addSql('ALTER TABLE country DROP iso31661_alpha3_code, DROP default_tax_percent, CHANGE tax_type_id tax_type_id INT NOT NULL');
        $this->addSql('ALTER TABLE delivery_type DROP is_delivery, DROP ready_for_shop');
        $this->addSql('ALTER TABLE discount_account_group ADD CONSTRAINT FK_F600553B4C7C611F FOREIGN KEY (discount_id) REFERENCES discount (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE discount_account_group ADD CONSTRAINT FK_F600553B869A3BF1 FOREIGN KEY (account_group_id) REFERENCES account_group (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE discount_account_group RENAME INDEX idx_dag_discount TO IDX_F600553B4C7C611F');
        $this->addSql('ALTER TABLE discount_account_group RENAME INDEX idx_dag_group TO IDX_F600553B869A3BF1');
        $this->addSql('ALTER TABLE discount_client ADD CONSTRAINT FK_A904286A4C7C611F FOREIGN KEY (discount_id) REFERENCES discount (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE discount_client ADD CONSTRAINT FK_A904286A19EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE discount_client RENAME INDEX idx_dc_discount TO IDX_A904286A4C7C611F');
        $this->addSql('ALTER TABLE discount_client RENAME INDEX idx_dc_client TO IDX_A904286A19EB6921');
        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY FK_order_delivery_type');
        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY FK_order_payment_type');
        $this->addSql('ALTER TABLE `order` ADD CONSTRAINT FK_F5299398DC058279 FOREIGN KEY (payment_type_id) REFERENCES payment_type (id)');
        $this->addSql('ALTER TABLE `order` ADD CONSTRAINT FK_F5299398CF52334D FOREIGN KEY (delivery_type_id) REFERENCES delivery_type (id)');
        $this->addSql('ALTER TABLE `order` RENAME INDEX fk_order_payment_type TO IDX_F5299398DC058279');
        $this->addSql('ALTER TABLE `order` RENAME INDEX fk_order_delivery_type TO IDX_F5299398CF52334D');
        $this->addSql('ALTER TABLE payment_type DROP provider_code, DROP configuration');
        $this->addSql('ALTER TABLE product DROP ready_for_shop, DROP retail_price, DROP discount_percent, DROP discount_price');
        $this->addSql('ALTER TABLE tax_type DROP remote_code');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE country ADD iso31661_alpha3_code VARCHAR(3) DEFAULT NULL, ADD default_tax_percent NUMERIC(5, 2) DEFAULT NULL, CHANGE tax_type_id tax_type_id INT DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_type ADD is_delivery TINYINT(1) DEFAULT 1 NOT NULL, ADD ready_for_shop TINYINT(1) DEFAULT 0 NOT NULL');
        $this->addSql('ALTER TABLE discount_account_group DROP FOREIGN KEY FK_F600553B4C7C611F');
        $this->addSql('ALTER TABLE discount_account_group DROP FOREIGN KEY FK_F600553B869A3BF1');
        $this->addSql('ALTER TABLE discount_account_group RENAME INDEX idx_f600553b4c7c611f TO IDX_dag_discount');
        $this->addSql('ALTER TABLE discount_account_group RENAME INDEX idx_f600553b869a3bf1 TO IDX_dag_group');
        $this->addSql('ALTER TABLE discount_client DROP FOREIGN KEY FK_A904286A4C7C611F');
        $this->addSql('ALTER TABLE discount_client DROP FOREIGN KEY FK_A904286A19EB6921');
        $this->addSql('ALTER TABLE discount_client RENAME INDEX idx_a904286a19eb6921 TO IDX_dc_client');
        $this->addSql('ALTER TABLE discount_client RENAME INDEX idx_a904286a4c7c611f TO IDX_dc_discount');
        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY FK_F5299398DC058279');
        $this->addSql('ALTER TABLE `order` DROP FOREIGN KEY FK_F5299398CF52334D');
        $this->addSql('ALTER TABLE `order` ADD CONSTRAINT FK_order_delivery_type FOREIGN KEY (delivery_type_id) REFERENCES delivery_type (id) ON UPDATE NO ACTION ON DELETE SET NULL');
        $this->addSql('ALTER TABLE `order` ADD CONSTRAINT FK_order_payment_type FOREIGN KEY (payment_type_id) REFERENCES payment_type (id) ON UPDATE NO ACTION ON DELETE SET NULL');
        $this->addSql('ALTER TABLE `order` RENAME INDEX idx_f5299398cf52334d TO FK_order_delivery_type');
        $this->addSql('ALTER TABLE `order` RENAME INDEX idx_f5299398dc058279 TO FK_order_payment_type');
        $this->addSql('ALTER TABLE payment_type ADD provider_code VARCHAR(50) DEFAULT NULL, ADD configuration JSON DEFAULT NULL');
        $this->addSql('ALTER TABLE product ADD ready_for_shop TINYINT(1) DEFAULT 0 NOT NULL, ADD retail_price DOUBLE PRECISION DEFAULT NULL, ADD discount_percent DOUBLE PRECISION DEFAULT NULL, ADD discount_price DOUBLE PRECISION DEFAULT NULL');
        $this->addSql('ALTER TABLE tax_type ADD remote_code VARCHAR(50) DEFAULT NULL');
    }
}
