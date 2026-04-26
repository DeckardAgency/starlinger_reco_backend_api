<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Auto-generated Migration: Please modify to your needs!
 */
final class Version20260426180814 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'SRECO-14 + SRECO-15: drop client.is_legal_entity and client.account_type (unused fields)';
    }

    public function up(Schema $schema): void
    {
        // this up() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE client DROP is_legal_entity, DROP account_type');
    }

    public function down(Schema $schema): void
    {
        // this down() migration is auto-generated, please modify it to your needs
        $this->addSql('ALTER TABLE client ADD is_legal_entity TINYINT(1) DEFAULT 0 NOT NULL, ADD account_type VARCHAR(50) DEFAULT NULL');
    }
}
