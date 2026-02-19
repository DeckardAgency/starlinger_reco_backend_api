<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:investigate-production',
    description: 'Investigate production database (starlinger_core) to discover RECO-relevant data',
)]
class InvestigateProductionDataCommand extends Command
{
    public function __construct(
        private readonly Connection $legacyConnection,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('section', 's', InputOption::VALUE_OPTIONAL, 'Run only a specific section (stores, tables, entities, eav, sample)', null)
            ->addOption('table', 't', InputOption::VALUE_OPTIONAL, 'Sample a specific table', null);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Production Database Investigation (starlinger_core)');

        $conn = $this->legacyConnection;
        $section = $input->getOption('section');

        try {
            if (!$section || $section === 'stores') {
                $this->investigateStores($io, $conn);
            }

            if (!$section || $section === 'tables') {
                $this->investigateAllTables($io, $conn);
            }

            if (!$section || $section === 'entities') {
                $this->investigateRecoEntities($io, $conn);
            }

            if (!$section || $section === 'eav') {
                $this->investigateEavStructure($io, $conn);
            }

            if (!$section || $section === 'sample') {
                $this->sampleKeyData($io, $conn);
            }

            if ($section === 'table') {
                $table = $input->getOption('table');
                if ($table) {
                    $this->sampleTable($io, $conn, $table);
                } else {
                    $io->error('--table option required with --section=table');
                    return Command::FAILURE;
                }
            }

            $io->success('Investigation complete.');
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error(['Investigation failed:', $e->getMessage()]);
            return Command::FAILURE;
        }
    }

    private function investigateStores(SymfonyStyle $io, Connection $conn): void
    {
        $io->section('1. MULTI-TENANT STORE DISCOVERY');

        // Stores
        $io->writeln('<info>s_store_entity:</info>');
        try {
            $stores = $conn->fetchAllAssociative('SELECT * FROM s_store_entity');
            if (empty($stores)) {
                $io->warning('No stores found');
            } else {
                $io->table(array_keys($stores[0]), $stores);
            }
        } catch (\Exception $e) {
            $io->warning('s_store_entity not found: ' . $e->getMessage());
        }

        // Websites
        $io->writeln('<info>s_website_entity:</info>');
        try {
            $websites = $conn->fetchAllAssociative('SELECT * FROM s_website_entity');
            if (empty($websites)) {
                $io->warning('No websites found');
            } else {
                $io->table(array_keys($websites[0]), $websites);
            }
        } catch (\Exception $e) {
            $io->warning('s_website_entity not found: ' . $e->getMessage());
        }

        // show_on_store sample from product_entity
        $io->writeln('<info>show_on_store samples from product_entity:</info>');
        try {
            $samples = $conn->fetchAllAssociative(
                'SELECT id, name, show_on_store FROM product_entity WHERE show_on_store IS NOT NULL LIMIT 10'
            );
            if (!empty($samples)) {
                $io->table(array_keys($samples[0]), $samples);
            }
        } catch (\Exception $e) {
            $io->warning('Could not sample show_on_store: ' . $e->getMessage());
        }

        // show_on_store distribution
        $io->writeln('<info>show_on_store distribution (product_entity):</info>');
        try {
            $dist = $conn->fetchAllAssociative(
                'SELECT show_on_store, COUNT(*) as cnt FROM product_entity GROUP BY show_on_store ORDER BY cnt DESC'
            );
            if (!empty($dist)) {
                $io->table(['show_on_store', 'cnt'], $dist);
            }
        } catch (\Exception $e) {
            $io->warning('Could not get distribution: ' . $e->getMessage());
        }

        // show_on_store distribution on account_entity
        $io->writeln('<info>show_on_store distribution (account_entity):</info>');
        try {
            $dist = $conn->fetchAllAssociative(
                'SELECT show_on_store, COUNT(*) as cnt FROM account_entity GROUP BY show_on_store ORDER BY cnt DESC'
            );
            if (!empty($dist)) {
                $io->table(['show_on_store', 'cnt'], $dist);
            }
        } catch (\Exception $e) {
            $io->warning('Could not get distribution: ' . $e->getMessage());
        }
    }

    private function investigateAllTables(SymfonyStyle $io, Connection $conn): void
    {
        $io->section('2. ALL TABLES WITH ROW COUNTS');

        $tables = $conn->fetchAllAssociative(
            "SELECT table_name, table_rows, ROUND(data_length/1024/1024, 2) as size_mb
             FROM information_schema.tables
             WHERE table_schema = 'starlinger_core'
             ORDER BY table_rows DESC"
        );

        $io->table(['table_name', 'table_rows', 'size_mb'], $tables);
        $io->writeln(sprintf('<info>Total tables: %d</info>', count($tables)));
    }

    private function investigateRecoEntities(SymfonyStyle $io, Connection $conn): void
    {
        $io->section('3. RECO-RELEVANT ENTITY TABLE DETAILS');

        $recoTables = [
            'account_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'Client'],
            'account_group_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'AccountGroup'],
            'product_entity' => ['columns' => 'id, name, code, price_base, currency_id, product_groups_id, is_active, show_on_store', 'filter' => null, 'alias' => 'Product'],
            'product_group_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'ProductGroup'],
            'tax_type_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'TaxType'],
            'country_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'Country'],
            'warehouse_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'Warehouse'],
            'delivery_type_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'DeliveryType'],
            'delivery_prices_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'DeliveryPrice'],
            'payment_type_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'PaymentType'],
            'discount_catalog_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'Discount'],
            'contact_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'Contact'],
            'contact_title_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'ContactTitle'],
            'department_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'Department'],
            'user_entity' => ['columns' => 'id, email, username, first_name, last_name, is_active, created, modified', 'filter' => null, 'alias' => 'User'],
            'address_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'Address'],
            'order_entity' => ['columns' => 'id, increment_id, order_state_id, account_id, created, modified', 'filter' => null, 'alias' => 'Order'],
            'order_item_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'OrderItem'],
            'packaging_price_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'PackagingPrice'],
            'fuel_surcharge_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'FuelSurcharge'],
            'import_manual_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'ImportManual'],
            'import_manual_status_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'ImportManualStatus'],
            'import_manual_type_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'ImportManualType'],
            'product_product_link_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'ProductProductLink'],
            'product_account_price_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'ClientProductPrice'],
            'city_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'City (lookup)'],
            'currency_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'Currency (lookup)'],
            'order_state_entity' => ['columns' => '*', 'filter' => null, 'alias' => 'OrderState (lookup)'],
        ];

        $results = [];
        foreach ($recoTables as $table => $config) {
            try {
                $count = $conn->fetchOne("SELECT COUNT(*) FROM `{$table}`");
                $activeCount = '-';

                // Check for active/inactive counts
                try {
                    $cols = $conn->fetchAllAssociative("SHOW COLUMNS FROM `{$table}`");
                    $colNames = array_column($cols, 'Field');

                    if (in_array('is_active', $colNames)) {
                        $activeCount = $conn->fetchOne("SELECT COUNT(*) FROM `{$table}` WHERE is_active = 1");
                    } elseif (in_array('active', $colNames)) {
                        $activeCount = $conn->fetchOne("SELECT COUNT(*) FROM `{$table}` WHERE active = 1");
                    } elseif (in_array('entity_state_id', $colNames)) {
                        $activeCount = $conn->fetchOne("SELECT COUNT(*) FROM `{$table}` WHERE entity_state_id = 1");
                    }
                } catch (\Exception $e) {
                    // ignore
                }

                $results[] = [$config['alias'], $table, $count, $activeCount];
            } catch (\Exception $e) {
                $results[] = [$config['alias'], $table, 'NOT FOUND', '-'];
            }
        }

        $io->table(['RECO Entity', 'Production Table', 'Total Rows', 'Active Rows'], $results);
    }

    private function investigateEavStructure(SymfonyStyle $io, Connection $conn): void
    {
        $io->section('4. EAV / LINK TABLE ANALYSIS');

        // Entity types
        $io->writeln('<info>entity_type table:</info>');
        try {
            $types = $conn->fetchAllAssociative('SELECT * FROM entity_type ORDER BY id');
            if (!empty($types)) {
                $io->table(array_keys($types[0]), $types);
            }
        } catch (\Exception $e) {
            $io->warning('entity_type not found: ' . $e->getMessage());
        }

        // Link tables
        $io->writeln('<info>Link tables (many-to-many):</info>');
        $linkTables = [
            'account_type_link_entity',
            'product_product_group_link_entity',
            'product_warehouse_link_entity',
            'discount_catalog_account_group_link_entity',
            'discount_catalog_account_link_entity',
            'delivery_prices_country_link_entity',
        ];

        $linkResults = [];
        foreach ($linkTables as $table) {
            try {
                $count = $conn->fetchOne("SELECT COUNT(*) FROM `{$table}`");
                $cols = $conn->fetchAllAssociative("SHOW COLUMNS FROM `{$table}`");
                $colNames = implode(', ', array_column($cols, 'Field'));
                $linkResults[] = [$table, $count, $colNames];
            } catch (\Exception $e) {
                $linkResults[] = [$table, 'NOT FOUND', '-'];
            }
        }

        $io->table(['Link Table', 'Row Count', 'Columns'], $linkResults);

        // entity_state_id distribution across key tables
        $io->writeln('<info>entity_state_id distribution:</info>');
        $esTables = ['product_entity', 'account_entity', 'contact_entity', 'order_entity', 'user_entity'];
        foreach ($esTables as $table) {
            try {
                $dist = $conn->fetchAllAssociative(
                    "SELECT entity_state_id, COUNT(*) as cnt FROM `{$table}` GROUP BY entity_state_id ORDER BY entity_state_id"
                );
                if (!empty($dist)) {
                    $io->writeln("  <comment>{$table}:</comment>");
                    $io->table(['entity_state_id', 'count'], $dist);
                }
            } catch (\Exception $e) {
                // Some tables might not have entity_state_id
            }
        }
    }

    private function sampleKeyData(SymfonyStyle $io, Connection $conn): void
    {
        $io->section('5. KEY LOOKUP DATA SAMPLES');

        // Currency
        $io->writeln('<info>currency_entity:</info>');
        try {
            $currencies = $conn->fetchAllAssociative('SELECT * FROM currency_entity');
            if (!empty($currencies)) {
                $io->table(array_keys($currencies[0]), $currencies);
            }
        } catch (\Exception $e) {
            $io->warning('currency_entity not found: ' . $e->getMessage());
        }

        // Order states
        $io->writeln('<info>order_state_entity:</info>');
        try {
            $states = $conn->fetchAllAssociative('SELECT * FROM order_state_entity');
            if (!empty($states)) {
                $io->table(array_keys($states[0]), $states);
            }
        } catch (\Exception $e) {
            $io->warning('order_state_entity not found: ' . $e->getMessage());
        }

        // City sample
        $io->writeln('<info>city_entity (sample):</info>');
        try {
            $cities = $conn->fetchAllAssociative('SELECT * FROM city_entity LIMIT 10');
            if (!empty($cities)) {
                $io->table(array_keys($cities[0]), $cities);
            }
        } catch (\Exception $e) {
            $io->warning('city_entity not found: ' . $e->getMessage());
        }

        // Product entity column structure
        $io->writeln('<info>product_entity columns:</info>');
        try {
            $cols = $conn->fetchAllAssociative('SHOW COLUMNS FROM product_entity');
            $io->table(['Field', 'Type', 'Null', 'Key', 'Default', 'Extra'], $cols);
        } catch (\Exception $e) {
            $io->warning('Could not show product_entity columns: ' . $e->getMessage());
        }

        // Account entity column structure
        $io->writeln('<info>account_entity columns:</info>');
        try {
            $cols = $conn->fetchAllAssociative('SHOW COLUMNS FROM account_entity');
            $io->table(['Field', 'Type', 'Null', 'Key', 'Default', 'Extra'], $cols);
        } catch (\Exception $e) {
            $io->warning('Could not show account_entity columns: ' . $e->getMessage());
        }

        // Order entity column structure
        $io->writeln('<info>order_entity columns:</info>');
        try {
            $cols = $conn->fetchAllAssociative('SHOW COLUMNS FROM order_entity');
            $io->table(['Field', 'Type', 'Null', 'Key', 'Default', 'Extra'], $cols);
        } catch (\Exception $e) {
            $io->warning('Could not show order_entity columns: ' . $e->getMessage());
        }

        // User entity column structure
        $io->writeln('<info>user_entity columns:</info>');
        try {
            $cols = $conn->fetchAllAssociative('SHOW COLUMNS FROM user_entity');
            $io->table(['Field', 'Type', 'Null', 'Key', 'Default', 'Extra'], $cols);
        } catch (\Exception $e) {
            $io->warning('Could not show user_entity columns: ' . $e->getMessage());
        }

        // Sample product rows with key fields
        $io->writeln('<info>product_entity sample (5 rows):</info>');
        try {
            $rows = $conn->fetchAllAssociative(
                'SELECT id, name, code, price_base, currency_id, product_groups_id, measure, active, show_on_store
                 FROM product_entity LIMIT 5'
            );
            if (!empty($rows)) {
                $io->table(array_keys($rows[0]), $rows);
            }
        } catch (\Exception $e) {
            $io->warning('Could not sample product_entity: ' . $e->getMessage());
        }

        // Sample account rows
        $io->writeln('<info>account_entity sample (5 rows):</info>');
        try {
            $rows = $conn->fetchAllAssociative(
                'SELECT id, name, code, phone, oib, email, is_active, owner_id
                 FROM account_entity LIMIT 5'
            );
            if (!empty($rows)) {
                $io->table(array_keys($rows[0]), $rows);
            }
        } catch (\Exception $e) {
            $io->warning('Could not sample account_entity: ' . $e->getMessage());
        }

        // Sample order rows
        $io->writeln('<info>order_entity sample (5 rows):</info>');
        try {
            $rows = $conn->fetchAllAssociative(
                'SELECT id, increment_id, order_state_id, account_id, created, modified
                 FROM order_entity LIMIT 5'
            );
            if (!empty($rows)) {
                $io->table(array_keys($rows[0]), $rows);
            }
        } catch (\Exception $e) {
            $io->warning('Could not sample order_entity: ' . $e->getMessage());
        }
    }

    private function sampleTable(SymfonyStyle $io, Connection $conn, string $table): void
    {
        $io->section(sprintf('Sampling table: %s', $table));

        // Columns
        try {
            $cols = $conn->fetchAllAssociative("SHOW COLUMNS FROM `{$table}`");
            $io->writeln('<info>Columns:</info>');
            $io->table(['Field', 'Type', 'Null', 'Key', 'Default', 'Extra'], $cols);
        } catch (\Exception $e) {
            $io->error("Table '{$table}' not found: " . $e->getMessage());
            return;
        }

        // Row count
        $count = $conn->fetchOne("SELECT COUNT(*) FROM `{$table}`");
        $io->writeln(sprintf('<info>Total rows: %s</info>', $count));

        // Sample rows
        $rows = $conn->fetchAllAssociative("SELECT * FROM `{$table}` LIMIT 10");
        if (!empty($rows)) {
            $io->writeln('<info>Sample rows (10):</info>');
            $io->table(array_keys($rows[0]), $rows);
        }
    }
}
