<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * DHL Express booking — phase 2: product customs data for non-EU exports
 * (commercial invoice lines). Neither legacy nor RECO had it; it is filled in
 * by admins on the product, or remembered from the first DHL booking of a product.
 */
final class Version20261016130000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Product HS code + country of origin (DHL Express customs)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product ADD hs_code VARCHAR(20) DEFAULT NULL, ADD country_of_origin VARCHAR(2) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE product DROP hs_code, DROP country_of_origin');
    }
}
