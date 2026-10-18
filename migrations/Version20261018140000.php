<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Discounts become price adjustments (Oct 2026): ONE signed percentage per
 * discount (`adjustment_percent`, negative = discount, positive = markup) that
 * applies to the listed clients only. Removed: validity dates, priority, the whole
 * AccountGroup feature (`account_group`, `discount_account_group`,
 * `client.account_group_id`) and the "no clients = applies to everyone" rule.
 *
 * Data handling, in order:
 *  a. account-group targeting is expanded into direct discount_client rows;
 *  b. active discounts whose meaning would otherwise change are deactivated
 *     (no client at all, expired, not yet valid, no percent) — counts printed;
 *  c. discount_percent (positive = discount) becomes adjustment_percent NOT NULL
 *     with the sign flipped; d. product_discount.rebate is flipped the same way;
 *  e.–h. columns, index and tables dropped.
 */
final class Version20261018140000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Discounts: signed adjustment_percent per client list; drop validity dates, priority and account groups';
    }

    public function up(Schema $schema): void
    {
        // a. Group targeting → direct client links (skip pairs that already exist; PK is (discount_id, client_id)).
        $expandSelect = 'SELECT DISTINCT dag.discount_id, c.id FROM discount_account_group dag'
            . ' JOIN client c ON c.account_group_id = dag.account_group_id'
            . ' WHERE NOT EXISTS (SELECT 1 FROM discount_client dc WHERE dc.discount_id = dag.discount_id AND dc.client_id = c.id)';
        $this->write(sprintf(
            'a. Expanding account-group targeting into %d new discount_client row(s)',
            (int) $this->connection->fetchOne("SELECT COUNT(*) FROM ({$expandSelect}) x")
        ));
        $this->addSql("INSERT INTO discount_client (discount_id, client_id) {$expandSelect}");

        // b. Deactivate active discounts that would otherwise change meaning. The
        // "no client" predicate also looks through the group links because the counts
        // are taken now, before (a) has run; after (a) both forms are equivalent.
        $reasons = [
            'no client (former "global" discount)' => 'NOT EXISTS (SELECT 1 FROM discount_client dc WHERE dc.discount_id = d.id)'
                . ' AND NOT EXISTS (SELECT 1 FROM discount_account_group dag JOIN client c ON c.account_group_id = dag.account_group_id WHERE dag.discount_id = d.id)',
            'expired (date_valid_to < now)' => 'd.date_valid_to IS NOT NULL AND d.date_valid_to < NOW()',
            'not yet valid (date_valid_from > now)' => 'd.date_valid_from IS NOT NULL AND d.date_valid_from > NOW()',
            'no percent (NULL or 0)' => 'd.discount_percent IS NULL OR d.discount_percent = 0',
        ];
        $all = [];
        foreach ($reasons as $label => $predicate) {
            $count = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM discount d WHERE d.is_active = 1 AND ({$predicate})");
            $this->write(sprintf('b. Deactivating %d active discount(s): %s', $count, $label));
            $this->addSql("UPDATE discount d SET d.is_active = 0 WHERE d.is_active = 1 AND ({$predicate})");
            $all[] = "({$predicate})";
        }
        $this->write(sprintf(
            'b. Total distinct discounts deactivated: %d (the per-reason counts above may overlap)',
            (int) $this->connection->fetchOne('SELECT COUNT(*) FROM discount d WHERE d.is_active = 1 AND (' . implode(' OR ', $all) . ')')
        ));

        // c. discount_percent (positive = discount) → adjustment_percent (signed, NOT NULL; negative = discount).
        $this->addSql('UPDATE discount SET discount_percent = 0 WHERE discount_percent IS NULL');
        $this->addSql('ALTER TABLE discount CHANGE discount_percent adjustment_percent NUMERIC(12, 4) NOT NULL');
        $this->addSql('UPDATE discount SET adjustment_percent = -adjustment_percent');

        // d. Per-link rebate follows the same sign convention.
        $this->addSql('UPDATE product_discount SET rebate = -rebate WHERE rebate IS NOT NULL AND rebate <> 0');

        // e. Discount: validity dates, priority (+ legacy_id where a DB still has it), the (is_active, priority) index.
        $drops = [];
        if ($this->indexExists('discount', 'idx_discount_active_priority')) {
            $drops[] = 'DROP INDEX idx_discount_active_priority';
        }
        $drops[] = 'DROP date_valid_from';
        $drops[] = 'DROP date_valid_to';
        $drops[] = 'DROP priority';
        if ($this->columnExists('discount', 'legacy_id')) {
            $drops[] = 'DROP legacy_id';
        }
        $this->addSql('ALTER TABLE discount ' . implode(', ', $drops));
        $this->addSql('CREATE INDEX idx_discount_active ON discount (is_active)');

        // f. ProductDiscount: validity dates.
        $this->addSql('ALTER TABLE product_discount DROP date_valid_from, DROP date_valid_to');

        // g. client.account_group_id: FK and index names differ per environment → look them up.
        foreach ($this->foreignKeysOn('client', 'account_group_id') as $fk) {
            $this->addSql("ALTER TABLE client DROP FOREIGN KEY `{$fk}`");
        }
        foreach ($this->indexesOn('client', 'account_group_id') as $index) {
            $this->addSql("DROP INDEX `{$index}` ON client");
        }
        $this->addSql('ALTER TABLE client DROP account_group_id');

        // h. The AccountGroup feature.
        $this->addSql('DROP TABLE discount_account_group');
        $this->addSql('DROP TABLE account_group');
    }

    public function down(Schema $schema): void
    {
        // Best effort: structures come back empty (group memberships are gone, the
        // deactivations of up() are not undone); the sign flips are reversed.
        $this->addSql('CREATE TABLE account_group (id INT AUTO_INCREMENT NOT NULL, name VARCHAR(255) NOT NULL, is_active TINYINT(1) DEFAULT 1 NOT NULL, created_at DATETIME NOT NULL, updated_at DATETIME NOT NULL, PRIMARY KEY(id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE discount_account_group (discount_id INT NOT NULL, account_group_id INT NOT NULL, INDEX IDX_F600553B4C7C611F (discount_id), INDEX IDX_F600553B869A3BF1 (account_group_id), PRIMARY KEY(discount_id, account_group_id)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE discount_account_group ADD CONSTRAINT FK_F600553B4C7C611F FOREIGN KEY (discount_id) REFERENCES discount (id) ON DELETE CASCADE');
        $this->addSql('ALTER TABLE discount_account_group ADD CONSTRAINT FK_F600553B869A3BF1 FOREIGN KEY (account_group_id) REFERENCES account_group (id) ON DELETE CASCADE');

        $this->addSql('ALTER TABLE client ADD account_group_id INT DEFAULT NULL');
        $this->addSql('CREATE INDEX IDX_C7440455869A3BF1 ON client (account_group_id)');
        $this->addSql('ALTER TABLE client ADD CONSTRAINT FK_C7440455869A3BF1 FOREIGN KEY (account_group_id) REFERENCES account_group (id) ON DELETE SET NULL');

        $this->addSql('ALTER TABLE product_discount ADD date_valid_from DATETIME DEFAULT NULL, ADD date_valid_to DATETIME DEFAULT NULL');
        $this->addSql('UPDATE product_discount SET rebate = -rebate WHERE rebate IS NOT NULL AND rebate <> 0');

        $this->addSql('DROP INDEX idx_discount_active ON discount');
        $this->addSql('ALTER TABLE discount ADD date_valid_from DATETIME DEFAULT NULL, ADD date_valid_to DATETIME DEFAULT NULL, ADD priority INT DEFAULT NULL, CHANGE adjustment_percent discount_percent NUMERIC(12, 4) DEFAULT NULL');
        $this->addSql('UPDATE discount SET discount_percent = -discount_percent, priority = 0');
        $this->addSql('CREATE INDEX idx_discount_active_priority ON discount (is_active, priority)');
    }

    private function columnExists(string $table, string $column): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
            [$table, $column]
        );
    }

    private function indexExists(string $table, string $index): bool
    {
        return (bool) $this->connection->fetchOne(
            'SELECT COUNT(*) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?',
            [$table, $index]
        );
    }

    /** @return string[] */
    private function foreignKeysOn(string $table, string $column): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT DISTINCT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE'
            . ' WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$table, $column]
        );
    }

    /** @return string[] */
    private function indexesOn(string $table, string $column): array
    {
        return $this->connection->fetchFirstColumn(
            'SELECT DISTINCT INDEX_NAME FROM information_schema.STATISTICS'
            . " WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? AND INDEX_NAME <> 'PRIMARY'",
            [$table, $column]
        );
    }
}
