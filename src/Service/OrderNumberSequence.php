<?php

namespace App\Service;

use Doctrine\DBAL\Connection;

/**
 * Hands out the ERP-facing order numbers: plain 7-digit integers that continue
 * the legacy webshop's increment_id series (4000061 → 4000062 → …), which the
 * ERP's "Webshop Order Number" field expects. Backed by an AUTO_INCREMENT table,
 * so concurrent checkouts never collide and no lock is needed; a rolled-back
 * checkout just leaves a gap, which the ERP doesn't mind.
 *
 * Seeding: migration Version20261117120000 starts the counter after the highest
 * imported number, and app:import-production calls skipPastExistingOrders()
 * after every order import, so re-importing fresher legacy data never hands out
 * a number an imported order already has.
 */
final class OrderNumberSequence
{
    public function __construct(private readonly Connection $connection) {}

    public function next(): string
    {
        $this->connection->executeStatement('INSERT INTO order_number_sequence () VALUES ()');

        return (string) $this->connection->lastInsertId();
    }

    /**
     * Moves the counter past the highest numeric order number stored, so the next
     * order continues after it. Only ever raises the counter: numbers already
     * handed out are never reused. Returns the number the next order follows.
     */
    public function skipPastExistingOrders(): int
    {
        $highestOrder = (int) $this->connection->fetchOne(
            "SELECT COALESCE(MAX(CAST(order_number AS UNSIGNED)), 0) FROM `order` WHERE order_number REGEXP '^[0-9]+$'"
        );
        $highestIssued = (int) $this->connection->fetchOne('SELECT COALESCE(MAX(id), 0) FROM order_number_sequence');

        if ($highestOrder <= $highestIssued) {
            return $highestIssued;
        }

        $this->connection->executeStatement('INSERT INTO order_number_sequence (id) VALUES (?)', [$highestOrder]);

        return $highestOrder;
    }
}
