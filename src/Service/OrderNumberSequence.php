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
 * imported number. At go-live, bump it above the legacy shop's last number:
 *   INSERT INTO order_number_sequence (id) VALUES (<legacy last number>);
 */
final class OrderNumberSequence
{
    public function __construct(private readonly Connection $connection) {}

    public function next(): string
    {
        $this->connection->executeStatement('INSERT INTO order_number_sequence () VALUES ()');

        return (string) $this->connection->lastInsertId();
    }
}
