<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Product links get a kind (Oct 2026): product_product_link.relation_type
 * ("alternative" | "replacement", App\Enum\ProductLinkRelationType) replaces the
 * never-used relation_type_id (NULL on every row; the legacy ids meant
 * Related/Upsell/Crossell/Bundle/Configurable, which RECO does not model).
 * Existing rows become "alternative". A product pair is linked at most once:
 * duplicate (parent, child) rows are dropped (lowest id survives) before the
 * unique index is added.
 */
final class Version20261018130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'product_product_link: relation_type (alternative|replacement) replaces relation_type_id; unique (parent_product_id, child_product_id)';
    }

    public function up(Schema $schema): void
    {
        // The DEFAULT already fills existing rows; the UPDATE makes the backfill explicit.
        $this->addSql("ALTER TABLE product_product_link ADD relation_type VARCHAR(20) NOT NULL DEFAULT 'alternative'");
        $this->addSql("UPDATE product_product_link SET relation_type = 'alternative'");
        // Self-join delete: keeps the lowest id of each (parent, child) pair. Works on
        // MySQL 8/9 (a subquery on the table being deleted from would not).
        $this->addSql('DELETE a FROM product_product_link a INNER JOIN product_product_link b ON a.parent_product_id = b.parent_product_id AND a.child_product_id = b.child_product_id AND a.id > b.id');
        $this->addSql('ALTER TABLE product_product_link DROP relation_type_id');
        $this->addSql('CREATE UNIQUE INDEX uniq_ppl_parent_child ON product_product_link (parent_product_id, child_product_id)');
    }

    public function down(Schema $schema): void
    {
        // Deleted duplicate rows are not restored; relation_type_id comes back NULL (as it always was).
        $this->addSql('DROP INDEX uniq_ppl_parent_child ON product_product_link');
        $this->addSql('ALTER TABLE product_product_link ADD relation_type_id INT UNSIGNED DEFAULT NULL');
        $this->addSql('ALTER TABLE product_product_link DROP relation_type');
    }
}
