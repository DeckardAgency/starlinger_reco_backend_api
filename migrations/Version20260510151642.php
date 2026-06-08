<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260510151642 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Order tracking: add tracking_event table + delivery_type.carrier_code column';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('CREATE TABLE tracking_event (id INT AUTO_INCREMENT NOT NULL, order_ref_id INT NOT NULL, status VARCHAR(50) NOT NULL, description LONGTEXT DEFAULT NULL, location VARCHAR(255) DEFAULT NULL, occurred_at DATETIME NOT NULL, recorded_at DATETIME NOT NULL, source VARCHAR(20) NOT NULL, INDEX idx_tracking_event_order (order_ref_id), INDEX idx_tracking_event_status (status), PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE tracking_event ADD CONSTRAINT FK_D0F2130AE238517C FOREIGN KEY (order_ref_id) REFERENCES `order` (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE delivery_type ADD carrier_code VARCHAR(20) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE tracking_event DROP FOREIGN KEY FK_D0F2130AE238517C');
        $this->addSql('DROP TABLE tracking_event');
        $this->addSql('ALTER TABLE delivery_type DROP carrier_code');
    }
}
