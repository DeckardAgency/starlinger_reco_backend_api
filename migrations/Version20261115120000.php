<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Orders get a "paid" status and a `paid_at` fact (Nov 2026). The client budget
 * (`client.amount_spent`) changes meaning from "everything ever ordered" to OPEN
 * PAYMENTS: the sum of submitted, unpaid, non-cancelled orders. Marking an order
 * paid frees its amount again.
 *
 * Data: existing delivered orders are considered paid (product decision), every
 * other active order stays open; amount_spent is recomputed from the orders per
 * client (per item, so agent orders are attributed to the customer ordered for).
 */
final class Version20261115120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Order paid status: paid_at column, delivered orders count as paid, client.amount_spent = open payments';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE `order` ADD paid_at DATETIME DEFAULT NULL');

        $delivered = (int) $this->connection->fetchOne("SELECT COUNT(*) FROM `order` WHERE status = 'delivered'");
        $this->write(sprintf('Marking %d delivered order(s) as paid (paid_at = last update)', $delivered));
        $this->addSql("UPDATE `order` SET paid_at = COALESCE(updated_at, created_at, NOW()) WHERE status = 'delivered'");

        $this->addSql($this->recomputeOpenPaymentsSql());
    }

    public function down(Schema $schema): void
    {
        // amount_spent cannot be restored to the old running total; it stays as open payments.
        $this->addSql("UPDATE `order` SET status = 'in_process' WHERE status = 'paid'");
        $this->addSql('ALTER TABLE `order` DROP paid_at');
    }

    /**
     * amount_spent := per-item sum of the client's open orders (item.on_behalf_of_client,
     * else order.on_behalf_of_client, else the placing user's client) — the same
     * attribution OrderPriceProcessor::resolveClientAmounts uses.
     */
    private function recomputeOpenPaymentsSql(): string
    {
        return <<<'SQL'
UPDATE client c
LEFT JOIN (
    SELECT COALESCE(oi.on_behalf_of_client_id, o.on_behalf_of_client_id, u.client_id) AS client_id,
           SUM(oi.subtotal) AS open_amount
    FROM order_item oi
    JOIN `order` o ON o.id = oi.order_ref_id
    LEFT JOIN user u ON u.id = o.user_id
    WHERE o.paid_at IS NULL
      AND o.is_draft = 0
      AND o.status NOT IN ('draft', 'canceled', 'reversal')
    GROUP BY COALESCE(oi.on_behalf_of_client_id, o.on_behalf_of_client_id, u.client_id)
) open_orders ON open_orders.client_id = c.id
SET c.amount_spent = COALESCE(open_orders.open_amount, 0)
SQL;
    }
}
