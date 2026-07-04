<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Performance: consolidates the indexes applied manually during the July 2026
 * performance work (schema changes are applied by hand on this project because
 * of the DBAL 3.10 / MySQL 9.5 schema-tool incompatibility — this migration
 * records them so other environments don't drift).
 *
 * Covers: webshop category browse and related-product lookups, client price
 * uniqueness, discount resolution, tracking batch queries, draft cleanup,
 * admin list filters/sorts, and support-ticket lookups. All are mirrored as
 * #[ORM\Index]/#[ORM\UniqueConstraint] attributes on the entities.
 */
final class Version20260703120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add performance indexes from the July 2026 performance review';
    }

    public function up(Schema $schema): void
    {
        // Webshop hot paths
        $this->addSql('ALTER TABLE product ADD INDEX idx_product_product_group_id (product_group_id)');
        $this->addSql('ALTER TABLE product_product_link ADD INDEX idx_ppl_parent (parent_product_id), ADD INDEX idx_ppl_child (child_product_id)');
        $this->addSql('ALTER TABLE client_product_price ADD UNIQUE INDEX uniq_cpp_client_product (client_id, product_id)');
        $this->addSql('ALTER TABLE discount ADD INDEX idx_discount_active_priority (is_active, priority)');

        // Order flows, tracking, drafts
        $this->addSql('ALTER TABLE tracking_event ADD INDEX idx_tracking_event_order_occurred (order_ref_id, occurred_at), ADD INDEX idx_tracking_event_source (source)');
        $this->addSql('ALTER TABLE `order` ADD INDEX idx_order_draft_saved (is_draft, last_saved_at), ADD INDEX idx_order_shipping_address_id (shipping_address_id), ADD INDEX idx_order_total_amount (total_amount), ADD INDEX idx_order_is_archived (is_archived)');
        $this->addSql('ALTER TABLE order_log ADD INDEX idx_order_log_status_change (previous_status, new_status)');

        // Admin list filters / lookups
        $this->addSql('ALTER TABLE client ADD INDEX idx_client_email (email)');
        $this->addSql('ALTER TABLE address ADD INDEX idx_address_postal_code (postal_code)');
        $this->addSql('ALTER TABLE support_ticket ADD INDEX idx_support_ticket_status_created (status, created_at), ADD INDEX idx_support_ticket_order_id (order_id)');
        $this->addSql('ALTER TABLE media_item ADD INDEX idx_media_item_mime_type (mime_type)');
        $this->addSql('ALTER TABLE documentation ADD INDEX idx_documentation_cat_pub_sort (category, is_published, sort_order)');
        $this->addSql('ALTER TABLE import_manual_entity ADD INDEX idx_import_manual_created_at (created_at)');

        // Delivery cost calculation
        $this->addSql('ALTER TABLE fuel_surcharge ADD INDEX idx_fuel_surcharge_type_date (delivery_type_id, date)');
        $this->addSql('ALTER TABLE delivery_price ADD INDEX idx_delivery_price_lookup (delivery_type_id, dhl_zone, size_from)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP INDEX idx_product_product_group_id');
        $this->addSql('ALTER TABLE product_product_link DROP INDEX idx_ppl_parent, DROP INDEX idx_ppl_child');
        $this->addSql('ALTER TABLE client_product_price DROP INDEX uniq_cpp_client_product');
        $this->addSql('ALTER TABLE discount DROP INDEX idx_discount_active_priority');
        $this->addSql('ALTER TABLE tracking_event DROP INDEX idx_tracking_event_order_occurred, DROP INDEX idx_tracking_event_source');
        $this->addSql('ALTER TABLE `order` DROP INDEX idx_order_draft_saved, DROP INDEX idx_order_shipping_address_id, DROP INDEX idx_order_total_amount, DROP INDEX idx_order_is_archived');
        $this->addSql('ALTER TABLE order_log DROP INDEX idx_order_log_status_change');
        $this->addSql('ALTER TABLE client DROP INDEX idx_client_email');
        $this->addSql('ALTER TABLE address DROP INDEX idx_address_postal_code');
        $this->addSql('ALTER TABLE support_ticket DROP INDEX idx_support_ticket_status_created, DROP INDEX idx_support_ticket_order_id');
        $this->addSql('ALTER TABLE media_item DROP INDEX idx_media_item_mime_type');
        $this->addSql('ALTER TABLE documentation DROP INDEX idx_documentation_cat_pub_sort');
        $this->addSql('ALTER TABLE import_manual_entity DROP INDEX idx_import_manual_created_at');
        $this->addSql('ALTER TABLE fuel_surcharge DROP INDEX idx_fuel_surcharge_type_date');
        $this->addSql('ALTER TABLE delivery_price DROP INDEX idx_delivery_price_lookup');
    }
}
