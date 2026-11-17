<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DHL Express (MyDHL API) booking — phase 1 (Oct 2026, see tasks/todo.md):
 *
 *  - dhl_shipment                   — one row per booked DHL Express shipment (AWB, label/invoice
 *                                     files, pickup, shipper/receiver as sent)
 *  - warehouse.postal_code          — needed for the shipper address; legacy had none
 *  - delivery_type.dhl_product_code — MyDHL product to book (null = Express Worldwide)
 *
 * Data fixes (guarded, idempotent — safe on local/grafit/prod which share the legacy import):
 *  - warehouse 5029 "Spare part department" (Hauptstrasse 43, Weissenbach) gets postal code
 *    2564 and country AT — the legacy DHL shipments used exactly this sender
 *  - DHL delivery types without a warehouse are linked to it
 *  - delivery_type.carrier_code normalised to uppercase (the entity's Assert\Choice);
 *    OrderPriceProcessor lowercases it when copying to order.tracking_carrier
 */
final class Version20261016120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'DHL Express shipments table, warehouse postal code, delivery type DHL product code';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("CREATE TABLE dhl_shipment (id INT AUTO_INCREMENT NOT NULL, order_id INT NOT NULL, created_by_id INT DEFAULT NULL, status VARCHAR(20) NOT NULL, awb VARCHAR(20) NOT NULL, product_code VARCHAR(5) NOT NULL, product_name VARCHAR(100) DEFAULT NULL, planned_shipping_at DATETIME NOT NULL, is_customs_declarable TINYINT(1) DEFAULT 0 NOT NULL, packages JSON NOT NULL, total_weight NUMERIC(10, 3) NOT NULL, shipper JSON NOT NULL, receiver JSON NOT NULL, documents JSON NOT NULL, label_format VARCHAR(20) DEFAULT 'a4' NOT NULL, price NUMERIC(10, 2) DEFAULT NULL, currency VARCHAR(3) DEFAULT NULL, estimated_delivery_at DATETIME DEFAULT NULL, pickup_confirmation_number VARCHAR(50) DEFAULT NULL, pickup_ready_at DATETIME DEFAULT NULL, pickup_close_time VARCHAR(5) DEFAULT NULL, pickup_cancelled_at DATETIME DEFAULT NULL, pickup_error LONGTEXT DEFAULT NULL, warnings JSON NOT NULL, cancelled_at DATETIME DEFAULT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, INDEX IDX_BB4F7389B03A8386 (created_by_id), INDEX idx_dhl_shipment_order (order_id), INDEX idx_dhl_shipment_awb (awb), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB");
        $this->addSql('ALTER TABLE dhl_shipment ADD CONSTRAINT FK_BB4F73898D9F6D38 FOREIGN KEY (order_id) REFERENCES `order` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE dhl_shipment ADD CONSTRAINT FK_BB4F7389B03A8386 FOREIGN KEY (created_by_id) REFERENCES `user` (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE warehouse ADD postal_code VARCHAR(20) DEFAULT NULL');
        $this->addSql('ALTER TABLE delivery_type ADD dhl_product_code VARCHAR(5) DEFAULT NULL');

        $this->addSql("UPDATE warehouse SET postal_code = '2564' WHERE id = 5029 AND postal_code IS NULL AND city = 'WEISSENBACH'");
        $this->addSql("UPDATE warehouse SET country_id = (SELECT id FROM country WHERE code = 'AT' LIMIT 1) WHERE id = 5029 AND country_id IS NULL AND city = 'WEISSENBACH'");
        $this->addSql("UPDATE delivery_type SET carrier_code = UPPER(carrier_code) WHERE carrier_code IS NOT NULL");
        $this->addSql("UPDATE delivery_type SET warehouse_id = 5029 WHERE warehouse_id IS NULL AND carrier_code = 'DHL' AND EXISTS (SELECT 1 FROM warehouse WHERE id = 5029)");
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE dhl_shipment DROP FOREIGN KEY FK_BB4F73898D9F6D38');
        $this->addSql('ALTER TABLE dhl_shipment DROP FOREIGN KEY FK_BB4F7389B03A8386');
        $this->addSql('DROP TABLE dhl_shipment');
        $this->addSql('ALTER TABLE warehouse DROP postal_code');
        $this->addSql('ALTER TABLE delivery_type DROP dhl_product_code');
        // Data fixes (postal code/country/warehouse link/carrier casing) are intentionally not reverted.
    }
}
