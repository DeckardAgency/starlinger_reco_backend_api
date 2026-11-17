<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DHL shipments: free-text reference (printed on the label), failed bookings kept
 * with DHL's reason (awb becomes nullable). Order numbers: a sequence table that
 * continues the legacy webshop's numeric series for the ERP.
 */
final class Version20261117120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'dhl_shipment.reference/error_message, nullable awb; order_number_sequence seeded after the highest numeric order number';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dhl_shipment ADD reference VARCHAR(35) DEFAULT NULL, ADD error_message LONGTEXT DEFAULT NULL, MODIFY awb VARCHAR(20) DEFAULT NULL');

        $this->addSql('CREATE TABLE order_number_sequence (id INT UNSIGNED AUTO_INCREMENT NOT NULL, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP, PRIMARY KEY (id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        // Continue the legacy increment_id series: the next submitted order gets MAX + 1.
        // (At go-live, insert the legacy shop's last number by hand to jump past it.)
        $this->addSql("INSERT INTO order_number_sequence (id) SELECT COALESCE(MAX(CAST(order_number AS UNSIGNED)), 4000000) FROM `order` WHERE order_number REGEXP '^[0-9]+$'");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE order_number_sequence');
        $this->addSql("DELETE FROM dhl_shipment WHERE status = 'failed'");
        $this->addSql('ALTER TABLE dhl_shipment DROP reference, DROP error_message, MODIFY awb VARCHAR(20) NOT NULL');
    }
}
