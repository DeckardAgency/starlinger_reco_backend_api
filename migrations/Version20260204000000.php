<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260204000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add isLegalEntity field to Client entity';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client ADD is_legal_entity TINYINT(1) DEFAULT 0 NOT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE client DROP is_legal_entity');
    }
}
