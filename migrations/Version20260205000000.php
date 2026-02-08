<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20260205000000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Create Address entity table for client addresses';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE address (
            id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            client_id BINARY(16) NOT NULL COMMENT \'(DC2Type:uuid)\',
            country_id BINARY(16) DEFAULT NULL COMMENT \'(DC2Type:uuid)\',
            street VARCHAR(255) NOT NULL,
            city VARCHAR(255) NOT NULL,
            postal_code VARCHAR(20) DEFAULT NULL,
            is_billing TINYINT(1) DEFAULT 0 NOT NULL,
            is_delivery TINYINT(1) DEFAULT 0 NOT NULL,
            is_active TINYINT(1) DEFAULT 1 NOT NULL,
            name VARCHAR(255) DEFAULT NULL,
            phone VARCHAR(50) DEFAULT NULL,
            email VARCHAR(255) DEFAULT NULL,
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL,
            PRIMARY KEY(id),
            INDEX idx_address_client (client_id),
            INDEX IDX_D4E6F81F92F3E70 (country_id),
            CONSTRAINT FK_D4E6F8119EB6921 FOREIGN KEY (client_id) REFERENCES client (id) ON DELETE CASCADE,
            CONSTRAINT FK_D4E6F81F92F3E70 FOREIGN KEY (country_id) REFERENCES country (id) ON DELETE SET NULL
        ) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE address');
    }
}
