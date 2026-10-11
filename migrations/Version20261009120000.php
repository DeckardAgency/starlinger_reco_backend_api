<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Third-party integration prerequisites (Oct 2026). Closes the legacy-data gaps
 * recorded in tasks/field-mapping-audit.md §3:
 *
 *  - order.erp_reference / erp_sent_at  ← legacy order_entity.remote_code / date_sent_to_erp
 *    (the ERP-side order reference; populated on every legacy order, previously dropped)
 *  - order.currency / currency_rate     ← legacy order_entity.currency_id / currency_rate
 *    (reco assumed EUR; all legacy orders are EUR @ 1.0, mapped anyway for correctness)
 *  - order.customer_reference           — the "internal reference" the customer types at
 *    checkout; previously written into `notes` (and only ever the "#0001" placeholder)
 *  - product.in_stock                   — availability flag for the integration. Legacy has
 *    the stock schema but never used it (no data exists), so this defaults to true until
 *    an ERP feed maintains it.
 *
 * All additions are nullable or defaulted: existing rows and the deployed frontends are
 * unaffected. Existing legacy-imported rows are backfilled separately by id join
 * (see tasks/todo.md, "Phase D").
 */
final class Version20261009120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Order ERP/customer reference + currency, product in_stock flag (integration prerequisites)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql("ALTER TABLE `order`
            ADD erp_reference VARCHAR(100) DEFAULT NULL,
            ADD erp_sent_at DATETIME DEFAULT NULL,
            ADD customer_reference VARCHAR(100) DEFAULT NULL,
            ADD currency VARCHAR(3) NOT NULL DEFAULT 'EUR',
            ADD currency_rate DOUBLE PRECISION NOT NULL DEFAULT 1");
        $this->addSql('ALTER TABLE product ADD in_stock TINYINT(1) NOT NULL DEFAULT 1');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order` DROP erp_reference, DROP erp_sent_at, DROP customer_reference, DROP currency, DROP currency_rate');
        $this->addSql('ALTER TABLE product DROP in_stock');
    }
}
