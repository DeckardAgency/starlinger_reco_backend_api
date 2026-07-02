<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Performance: index product_discount(product_id) and (discount_id). The discount
 * resolver looks up campaign discounts by product_id per row while serializing product
 * and order collections; without an index each lookup full-scanned product_discount.
 */
final class Version20260702120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add indexes on product_discount(product_id) and (discount_id)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE INDEX idx_product_discount_product ON product_discount (product_id)');
        $this->addSql('CREATE INDEX idx_product_discount_discount ON product_discount (discount_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP INDEX idx_product_discount_product ON product_discount');
        $this->addSql('DROP INDEX idx_product_discount_discount ON product_discount');
    }
}
