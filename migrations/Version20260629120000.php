<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Client agent — per-item delegation: add order_item.on_behalf_of_client_id so an
 * agent can place a single order with lines on behalf of different managed clients.
 */
final class Version20260629120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add order_item.on_behalf_of_client_id (per-item client-agent delegation)';
    }

    public function up(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE order_item ADD on_behalf_of_client_id INT DEFAULT NULL
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE order_item
                ADD CONSTRAINT FK_52EA1F09_OBOC FOREIGN KEY (on_behalf_of_client_id) REFERENCES client (id)
        SQL);
        $this->addSql(<<<'SQL'
            CREATE INDEX IDX_ORDER_ITEM_OBOC ON order_item (on_behalf_of_client_id)
        SQL);
    }

    public function down(Schema $schema): void
    {
        $this->addSql(<<<'SQL'
            ALTER TABLE order_item DROP FOREIGN KEY FK_52EA1F09_OBOC
        SQL);
        $this->addSql(<<<'SQL'
            DROP INDEX IDX_ORDER_ITEM_OBOC ON order_item
        SQL);
        $this->addSql(<<<'SQL'
            ALTER TABLE order_item DROP on_behalf_of_client_id
        SQL);
    }
}
