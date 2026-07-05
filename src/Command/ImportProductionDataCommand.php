<?php

namespace App\Command;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:import-production',
    description: 'Import production data from starlinger_core into RECO',
)]
class ImportProductionDataCommand extends Command
{
    private SymfonyStyle $io;
    private bool $dryRun = false;
    private int $batchSize = 100;
    private ?string $storeFilter = null;
    private string $preferredStore = '3'; // English store
    private array $currencyMap = [];
    private array $orderStateMap = [];
    private array $cityMap = [];
    private array $taxTypeMap = []; // legacy tax_type id → percent
    private array $accountUserMap = []; // account_id → first user_id
    private array $importedIds = [];
    private array $stats = [];

    public function __construct(
        private readonly Connection $legacyConnection,
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Simulate import without writing data')
            ->addOption('entity', null, InputOption::VALUE_OPTIONAL, 'Import only a specific entity (e.g. tax_type, product)')
            ->addOption('batch-size', null, InputOption::VALUE_OPTIONAL, 'Batch size for large tables', 100)
            ->addOption('store-id', null, InputOption::VALUE_OPTIONAL, 'Filter by store ID (from show_on_store)', null)
            ->addOption('default-password', null, InputOption::VALUE_OPTIONAL, 'Default password for imported users', 'recouser123!');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->io = new SymfonyStyle($input, $output);
        $this->dryRun = $input->getOption('dry-run');
        $this->batchSize = (int) $input->getOption('batch-size');
        $this->storeFilter = $input->getOption('store-id');
        $defaultPassword = $input->getOption('default-password');

        $this->io->title('Import Production Data' . ($this->dryRun ? ' [DRY RUN]' : ''));

        if ($this->storeFilter) {
            $this->io->note("Filtering by store ID: {$this->storeFilter}");
        }

        $entityFilter = $input->getOption('entity');
        $legacy = $this->legacyConnection;
        $conn = $this->entityManager->getConnection();

        try {
            // Build lookup maps
            $this->buildLookupMaps($legacy);

            // Truncate all target tables before import (reverse dependency order)
            if (!$this->dryRun && !$entityFilter) {
                $this->io->section('Clearing existing data');
                $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');
                $tablesToTruncate = [
                    'product_discount', 'import_manual_entity', 'order_log', 'order_item', '`order`',
                    'order_info_message', 'order_info_request',
                    'client_product_price', 'product_product_link',
                    'fuel_surcharge', 'delivery_price', 'product', 'product_group',
                    'delivery_type', 'address', '`user`', 'warehouse',
                    'client', 'country',
                    'discount', 'packaging_price', 'payment_type',
                    'import_manual_type_entity', 'import_manual_status_entity', 'account_group', 'tax_type',
                ];
                foreach ($tablesToTruncate as $table) {
                    try {
                        $conn->executeStatement("TRUNCATE TABLE {$table}");
                    } catch (\Exception $e) {
                        // Table may not exist yet, skip
                    }
                }
                $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
                $this->io->writeln('  All tables truncated');
            }

            // Import in dependency order
            $importOrder = [
                // Level 1 — Independent
                'tax_type' => 'importTaxTypes',
                'account_group' => 'importAccountGroups',
                'import_manual_status' => 'importImportManualStatuses',
                'import_manual_type' => 'importImportManualTypes',
                'payment_type' => 'importPaymentTypes',
                'packaging_price' => 'importPackagingPrices',
                'discount' => 'importDiscounts',
                // Level 2
                'country' => 'importCountries',
                'client' => 'importClients',
                // Level 3
                'warehouse' => 'importWarehouses',
                'user' => 'importUsers',
                'address' => 'importAddresses',
                // Level 4
                'delivery_type' => 'importDeliveryTypes',
                'product_group' => 'importProductGroups',
                // Level 5
                'delivery_price' => 'importDeliveryPrices',
                'fuel_surcharge' => 'importFuelSurcharges',
                'product' => 'importProducts',
                // Level 6
                'product_product_link' => 'importProductProductLinks',
                'client_product_price' => 'importClientProductPrices',
                'order' => 'importOrders',
                'order_item' => 'importOrderItems',
                'order_log' => 'importOrderLogs',
                'product_discount' => 'importProductDiscounts',
                'import_manual' => 'importImportManuals',
            ];

            foreach ($importOrder as $key => $method) {
                if ($entityFilter && $entityFilter !== $key) {
                    continue;
                }
                $this->$method($legacy, $conn, $defaultPassword);
            }

            // Summary
            $this->io->section('Import Summary');
            $summaryRows = [];
            foreach ($this->stats as $entity => $count) {
                $summaryRows[] = [$entity, $count];
            }
            $this->io->table(['Entity', 'Rows Imported'], $summaryRows);

            $this->io->success('Import complete' . ($this->dryRun ? ' (dry run — no data written)' : ''));
            return Command::SUCCESS;

        } catch (\Exception $e) {
            $this->io->error(['Import failed:', $e->getMessage(), $e->getTraceAsString()]);
            return Command::FAILURE;
        }
    }

    private function buildLookupMaps(Connection $legacy): void
    {
        $this->io->section('Building lookup maps...');

        // Currency map: id → code
        try {
            $currencies = $legacy->fetchAllAssociative('SELECT id, code FROM currency_entity');
            foreach ($currencies as $row) {
                $this->currencyMap[(int) $row['id']] = $row['code'];
            }
            $this->io->writeln(sprintf('  Currency map: %d entries', count($this->currencyMap)));
        } catch (\Exception $e) {
            $this->io->warning('Could not build currency map: ' . $e->getMessage());
        }

        // Tax type map: id → percent (for order_item tax_percent)
        try {
            $taxTypes = $legacy->fetchAllAssociative('SELECT id, percent FROM tax_type_entity');
            foreach ($taxTypes as $row) {
                $this->taxTypeMap[(int) $row['id']] = (float) $row['percent'];
            }
        } catch (\Exception $e) {
            $this->io->warning('Could not build tax type map: ' . $e->getMessage());
        }

        // Order state map: id → status string
        try {
            $states = $legacy->fetchAllAssociative('SELECT id, name FROM order_state_entity');
            foreach ($states as $row) {
                $this->orderStateMap[(int) $row['id']] = $this->mapOrderState($row['name']);
            }
            $this->io->writeln(sprintf('  Order state map: %d entries', count($this->orderStateMap)));
        } catch (\Exception $e) {
            $this->io->warning('Could not build order state map: ' . $e->getMessage());
        }

        // City map: only load cities referenced by addresses and warehouses (NOT all 2.8M!)
        try {
            $cityIds = [];
            // Collect city_ids from address_entity
            $addrCities = $legacy->fetchAllAssociative('SELECT DISTINCT city_id FROM address_entity WHERE city_id IS NOT NULL AND city_id > 0');
            foreach ($addrCities as $r) {
                $cityIds[] = (int) $r['city_id'];
            }
            // Collect city_ids from warehouse_entity
            $whCities = $legacy->fetchAllAssociative('SELECT DISTINCT city_id FROM warehouse_entity WHERE city_id IS NOT NULL AND city_id > 0');
            foreach ($whCities as $r) {
                $cityIds[] = (int) $r['city_id'];
            }
            // Collect city_ids from order address snapshots (needed to compose "street, city" order addresses)
            $orderCities = $legacy->fetchAllAssociative(
                'SELECT DISTINCT account_shipping_city_id AS city_id FROM order_entity WHERE account_shipping_city_id IS NOT NULL AND account_shipping_city_id > 0
                 UNION SELECT DISTINCT account_billing_city_id FROM order_entity WHERE account_billing_city_id IS NOT NULL AND account_billing_city_id > 0'
            );
            foreach ($orderCities as $r) {
                $cityIds[] = (int) $r['city_id'];
            }
            $cityIds = array_unique($cityIds);

            if (!empty($cityIds)) {
                $placeholders = implode(',', $cityIds);
                // postal_code and country_id also live on city_entity (address_entity has neither)
                $cities = $legacy->fetchAllAssociative("SELECT id, name, postal_code, country_id FROM city_entity WHERE id IN ({$placeholders})");
                foreach ($cities as $row) {
                    $this->cityMap[(int) $row['id']] = [
                        'name' => $row['name'],
                        'postal_code' => $row['postal_code'] ?? null,
                        'country_id' => !empty($row['country_id']) ? (int) $row['country_id'] : null,
                    ];
                }
            }
            $this->io->writeln(sprintf('  City map: %d entries (filtered from referenced)', count($this->cityMap)));
        } catch (\Exception $e) {
            $this->io->warning('Could not build city map: ' . $e->getMessage());
        }
    }

    private function mapOrderState(string $name): string
    {
        $name = strtolower(trim($name));
        return match (true) {
            str_contains($name, 'draft') => 'draft',
            str_contains($name, 'new') => 'new',
            str_contains($name, 'process') => 'in_process',
            str_contains($name, 'deliver') => 'delivered',
            str_contains($name, 'cancel') => 'canceled',
            str_contains($name, 'reversal') => 'reversal',
            str_contains($name, 'waiting'), str_contains($name, 'payment') => 'waiting_for_payment',
            str_contains($name, 'ready'), str_contains($name, 'shipment') => 'ready_for_shipment',
            str_contains($name, 'ship'), str_contains($name, 'dispatch'), str_contains($name, 'sent') => 'shipped',
            default => 'new',
        };
    }

    private function matchesStoreFilter(?string $showOnStore): bool
    {
        if (!$this->storeFilter) {
            return true;
        }
        if (empty($showOnStore)) {
            return false;
        }

        // Format is {"3":1,"6":1} — keys are store IDs, values are 1/0
        $decoded = @json_decode($showOnStore, true);
        if (is_array($decoded)) {
            return !empty($decoded[$this->storeFilter]);
        }

        // Try PHP unserialize as fallback
        $decoded = @unserialize($showOnStore);
        if (is_array($decoded)) {
            return !empty($decoded[$this->storeFilter]);
        }

        return false;
    }

    /**
     * Safely convert a value to 0 or 1 for TINYINT boolean columns.
     * Handles empty strings, null, and other edge cases that MySQL strict mode rejects.
     */
    private function toBool($value): int
    {
        if ($value === '' || $value === null) {
            return 0;
        }
        return (int) (bool) $value;
    }

    /**
     * Extract a scalar value from a potential JSON per-store field.
     * Returns the raw value if not JSON, or the preferred store's value if JSON.
     */
    private function extractLocalizedValue($value)
    {
        if ($value === null || $value === '') {
            return null;
        }
        if (!is_string($value) || $value[0] !== '{') {
            return $value;
        }
        $decoded = @json_decode($value, true);
        if (!is_array($decoded)) {
            return $value;
        }
        return $decoded[$this->preferredStore] ?? $decoded['3'] ?? reset($decoded) ?: null;
    }

    /**
     * Extract localized name from JSON format {"3":"English name","6":"German name"}
     */
    private function extractLocalizedName(?string $jsonName, string $fallback = 'Unknown'): string
    {
        if (empty($jsonName)) {
            return $fallback;
        }

        $decoded = @json_decode($jsonName, true);
        if (!is_array($decoded)) {
            // Plain string, not JSON
            return $jsonName;
        }

        // Prefer English store (3), then German store (6), then first value
        return $decoded[$this->preferredStore]
            ?? $decoded['3']
            ?? reset($decoded)
            ?: $fallback;
    }

    private function importBatch(Connection $conn, string $table, array $rows): void
    {
        if ($this->dryRun || empty($rows)) {
            return;
        }

        $conn->beginTransaction();
        try {
            // Disable FK checks for ID setting
            $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 0');

            foreach ($rows as $row) {
                // Sanitize zero dates in all datetime columns
                foreach ($row as $key => $value) {
                    if (is_string($value) && str_starts_with($value, '0000-00-00')) {
                        $row[$key] = date('Y-m-d H:i:s');
                    }
                }
                $conn->insert($table, $row);
            }

            $conn->executeStatement('SET FOREIGN_KEY_CHECKS = 1');
            $conn->commit();
        } catch (\Exception $e) {
            $conn->rollBack();
            throw $e;
        }
    }

    private function resetAutoIncrement(Connection $conn, string $table): void
    {
        if ($this->dryRun) {
            return;
        }

        try {
            // Strip backticks if already present to avoid double-escaping
            $cleanTable = trim($table, '`');
            $maxId = $conn->fetchOne("SELECT COALESCE(MAX(id), 0) FROM `{$cleanTable}`");
            $conn->executeStatement("ALTER TABLE `{$cleanTable}` AUTO_INCREMENT = " . ((int) $maxId + 1));
        } catch (\Exception $e) {
            $this->io->warning("Could not reset AUTO_INCREMENT for {$table}: " . $e->getMessage());
        }
    }

    // ─── Level 1: Independent ───────────────────────────────────────────

    private function importTaxTypes(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing TaxTypes');
        $source = $legacy->fetchAllAssociative('SELECT * FROM tax_type_entity');
        $rows = [];

        foreach ($source as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'] ?? 'Unknown',
                'percent' => $row['percent'] ?? '0.00',
                'remote_id' => isset($row['remote_id']) ? (int) $row['remote_id'] : null,
                'is_active' => $this->toBool($row['is_active'] ?? 1),
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
            $this->importedIds['tax_type'][(int) $row['id']] = true;
        }

        $this->io->writeln(sprintf('  Found %d tax types', count($rows)));
        $this->importBatch($conn, 'tax_type', $rows);
        $this->resetAutoIncrement($conn, 'tax_type');
        $this->stats['TaxType'] = count($rows);
    }

    private function importAccountGroups(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing AccountGroups');
        $source = $legacy->fetchAllAssociative('SELECT * FROM account_group_entity');
        $rows = [];

        foreach ($source as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'] ?? 'Unknown',
                'is_active' => $this->toBool($row['is_active'] ?? $row['active'] ?? 1),
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
            $this->importedIds['account_group'][(int) $row['id']] = true;
        }

        $this->io->writeln(sprintf('  Found %d account groups', count($rows)));
        $this->importBatch($conn, 'account_group', $rows);
        $this->resetAutoIncrement($conn, 'account_group');
        $this->stats['AccountGroup'] = count($rows);
    }


    private function importImportManualStatuses(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing ImportManualStatuses');
        $source = $legacy->fetchAllAssociative('SELECT * FROM import_manual_status_entity');
        $rows = [];

        foreach ($source as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'] ?? null,
                'created_at' => $row['created'] ?? $row['created_at'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? $row['updated_at'] ?? date('Y-m-d H:i:s'),
            ];
        }

        $this->io->writeln(sprintf('  Found %d import manual statuses', count($rows)));
        $this->importBatch($conn, 'import_manual_status_entity', $rows);
        $this->resetAutoIncrement($conn, 'import_manual_status_entity');
        $this->stats['ImportManualStatus'] = count($rows);
    }

    private function importImportManualTypes(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing ImportManualTypes');
        $source = $legacy->fetchAllAssociative('SELECT * FROM import_manual_type_entity');
        $rows = [];

        foreach ($source as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'] ?? null,
                'manager_code' => $row['manager_code'] ?? null,
                'method' => $row['method'] ?? null,
                'estimated_duration' => isset($row['estimated_duration']) ? (int) $row['estimated_duration'] : null,
                'manual_type_code' => $row['manual_type_code'] ?? null,
                'send_email' => isset($row['send_email']) ? $this->toBool($row['send_email']) : null,
                'email_template_code' => $row['email_template_code'] ?? null,
                'created_at' => $row['created'] ?? $row['created_at'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? $row['updated_at'] ?? date('Y-m-d H:i:s'),
            ];
        }

        $this->io->writeln(sprintf('  Found %d import manual types', count($rows)));
        $this->importBatch($conn, 'import_manual_type_entity', $rows);
        $this->resetAutoIncrement($conn, 'import_manual_type_entity');
        $this->stats['ImportManualType'] = count($rows);
    }

    private function importPaymentTypes(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing PaymentTypes');
        $source = $legacy->fetchAllAssociative('SELECT * FROM payment_type_entity');
        $rows = [];

        foreach ($source as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => $this->extractLocalizedName($row['name'] ?? null, 'Unknown'),
                'short_description' => $this->extractLocalizedValue($row['short_description'] ?? $row['description'] ?? null),
                'enable_installments' => $this->toBool($row['enable_installments'] ?? 0),
                'remote_code' => $row['remote_code'] ?? null,
                'fiscal_code' => $row['fiscal_code'] ?? null,
                'payment_fee' => $this->extractLocalizedValue($row['payment_fee'] ?? null),
                'min_cart_total' => $this->extractLocalizedValue($row['min_cart_total'] ?? null),
                'max_cart_total' => $this->extractLocalizedValue($row['max_cart_total'] ?? null),
                'color' => $row['color'] ?? null,
                'icon' => $row['icon'] ?? null,
                'allow_recurring_payment' => $this->toBool($row['allow_recurring_payment'] ?? 0),
                'recurring_days_reminder' => isset($row['recurring_days_reminder']) ? (int) $row['recurring_days_reminder'] : null,
                'use_as_default' => $this->toBool($this->extractLocalizedValue($row['use_as_default'] ?? null) ?? 0),
                'is_active' => $this->toBool($row['is_active'] ?? $row['active'] ?? 1),
                'sort_order' => (int) ($row['sort_order'] ?? $row['ord'] ?? 0),
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
        }

        $this->io->writeln(sprintf('  Found %d payment types', count($rows)));
        $this->importBatch($conn, 'payment_type', $rows);
        $this->resetAutoIncrement($conn, 'payment_type');
        $this->stats['PaymentType'] = count($rows);
    }

    private function importPackagingPrices(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing PackagingPrices');
        $source = $legacy->fetchAllAssociative('SELECT * FROM packaging_price_entity');
        $rows = [];

        foreach ($source as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'] ?? null,
                'size_from' => $row['size_from'] ?? null,
                'size_to' => $row['size_to'] ?? null,
                'price_base' => $row['price_base'] ?? '0.0000',
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
        }

        $this->io->writeln(sprintf('  Found %d packaging prices', count($rows)));
        $this->importBatch($conn, 'packaging_price', $rows);
        $this->resetAutoIncrement($conn, 'packaging_price');
        $this->stats['PackagingPrice'] = count($rows);
    }

    private function importDiscounts(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing Discounts');
        $source = $legacy->fetchAllAssociative('SELECT * FROM discount_catalog_entity');
        $rows = [];

        foreach ($source as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'] ?? 'Unknown',
                'is_active' => $this->toBool($row['is_active'] ?? $row['active'] ?? 1),
                'date_valid_from' => $row['date_from'] ?? null,
                'date_valid_to' => $row['date_to'] ?? null,
                'priority' => (int) ($row['priority'] ?? $row['ord'] ?? 0),
                'discount_percent' => $row['discount_percent'] ?? $row['discount'] ?? null,
                'rules' => !empty($row['rules']) ? $row['rules'] : null,
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
        }

        $this->io->writeln(sprintf('  Found %d discounts', count($rows)));
        $this->importBatch($conn, 'discount', $rows);
        $this->resetAutoIncrement($conn, 'discount');
        $this->stats['Discount'] = count($rows);
    }

    // ─── Level 2: Depends on Level 1 ───────────────────────────────────

    private function importCountries(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing Countries');
        $source = $legacy->fetchAllAssociative('SELECT * FROM country_entity');
        $rows = [];

        foreach ($source as $row) {
            // Legacy tax_type_id is NULL for all rows but reco country.tax_type_id is NOT NULL:
            // default to standard tax type 1 (matches existing production-reco data)
            $taxTypeId = !empty($row['tax_type_id']) ? (int) $row['tax_type_id'] : 1;
            $countryName = $this->extractLocalizedName($row['name'] ?? null, 'Unknown');
            $code = $row['code'] ?? null;
            if (empty($code)) {
                // Generate 2-char code: first char of name + last digit of ID
                $firstChar = strtoupper(substr($countryName, 0, 1));
                $code = $firstChar . ((int) $row['id'] % 10);
            }
            // Ensure code is max 2 chars and unique
            $code = substr($code, 0, 2);
            static $usedCodes = [];
            if (isset($usedCodes[$code])) {
                // Use first char + cycling second char (A-Z, 0-9)
                $base = $code[0];
                for ($c = 'A'; $c <= 'Z'; $c++) {
                    $alt = $base . $c;
                    if (!isset($usedCodes[$alt])) {
                        $code = $alt;
                        break;
                    }
                }
            }
            $usedCodes[$code] = true;

            $rows[] = [
                'id' => (int) $row['id'],
                'name' => $countryName,
                'code' => $code,
                'european_union' => $this->toBool($row['european_union'] ?? $row['eu'] ?? 0),
                'dhl_zone' => isset($row['dhl_zone_id']) ? (int) $row['dhl_zone_id'] : null,
                'tax_type_id' => $taxTypeId,
                'is_active' => $this->toBool($row['is_active'] ?? $row['active'] ?? 1),
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
            $this->importedIds['country'][(int) $row['id']] = true;
        }

        $this->io->writeln(sprintf('  Found %d countries', count($rows)));
        $this->importBatch($conn, 'country', $rows);
        $this->resetAutoIncrement($conn, 'country');
        $this->stats['Country'] = count($rows);
    }

    private function importClients(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing Clients');
        // account_entity has NO show_on_store — import all accounts
        $source = $legacy->fetchAllAssociative('SELECT * FROM account_entity WHERE entity_state_id = 1 OR entity_state_id IS NULL');
        $rows = [];

        foreach ($source as $row) {
            $accountGroupId = isset($row['account_group_id']) && $row['account_group_id'] ? (int) $row['account_group_id'] : null;
            // If account_group_entity has 0 rows, set to null to avoid FK violation
            if ($accountGroupId && empty($this->importedIds['account_group'])) {
                $accountGroupId = null;
            }

            $rows[] = [
                'id' => (int) $row['id'],
                'name' => $row['name'] ?? 'Unknown',
                'code' => $row['code'] ?? 'ACC-' . $row['id'],
                'description' => $row['description'] ?? null,
                'address' => null, // Address stored in address_entity, not inline
                'phone_number' => $row['phone'] ?? null,
                'email' => $row['email'] ?? null,
                'vat_number' => $row['oib'] ?? null,
                'purchase_limit' => $row['purchase_limit'] ?? null,
                'amount_spent' => $row['amount_spent'] ?? null,
                'other_phone' => $row['phone_2'] ?? null,
                'other_email' => $row['secondary_email'] ?? null,
                'fax' => $row['fax'] ?? null,
                'web' => $row['web'] ?? null,
                'max_active_users' => null,
                'is_active' => $this->toBool($row['is_active'] ?? 1),
                'is_archived' => 0,
                'account_group_id' => $accountGroupId,
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
            $this->importedIds['client'][(int) $row['id']] = true;
        }

        $this->io->writeln(sprintf('  Found %d clients', count($rows)));
        $this->importBatch($conn, 'client', $rows);
        $this->resetAutoIncrement($conn, 'client');
        $this->stats['Client'] = count($rows);
    }

    // ─── Level 3: Depends on Level 2 ───────────────────────────────────

    private function importWarehouses(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing Warehouses');
        $source = $legacy->fetchAllAssociative('SELECT * FROM warehouse_entity');
        $rows = [];

        foreach ($source as $row) {
            $city = isset($row['city_id']) && $row['city_id']
                ? ($this->cityMap[(int) $row['city_id']] ?? null)
                : null;
            $cityName = $city['name'] ?? $row['city'] ?? null;

            $rows[] = [
                'id' => (int) $row['id'],
                'country_id' => $city['country_id'] ?? null,
                'name' => $this->extractLocalizedName($row['name'] ?? null, 'Unknown'),
                'code' => $row['code'] ?? null,
                'address' => $row['address'] ?? null,
                'city' => $cityName,
                'email' => $row['email'] ?? null,
                'phone' => $row['phone'] ?? null,
                'office_name' => $this->extractLocalizedName($row['office_name'] ?? null, '') ?: null,
                'url' => $row['url'] ?? null,
                'latitude' => $row['latitude'] ?? null,
                'longitude' => $row['longitude'] ?? null,
                'show_as_location' => $this->toBool($row['show_as_location'] ?? 0),
                'description' => $this->extractLocalizedName($row['description'] ?? null, '') ?: null,
                'contact_person' => $row['contact_person'] ?? null,
                'is_active' => $this->toBool($row['active'] ?? $row['is_active'] ?? 1),
                'ready_for_shop' => $this->toBool($row['ready_for_shop'] ?? 0),
                'keep_url' => $this->toBool($row['keep_url'] ?? 0),
                'auto_generate_url' => $this->toBool($row['auto_generate_url'] ?? 0),
                'remote_id' => isset($row['remote_id']) ? (int) $row['remote_id'] : null,
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
            $this->importedIds['warehouse'][(int) $row['id']] = true;
        }

        $this->io->writeln(sprintf('  Found %d warehouses', count($rows)));
        $this->importBatch($conn, 'warehouse', $rows);
        $this->resetAutoIncrement($conn, 'warehouse');
        $this->stats['Warehouse'] = count($rows);
    }


    private function importUsers(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing Users (from production contacts + user_entity)');

        $tempUser = new \App\Entity\User();
        $hashedPassword = $this->passwordHasher->hashPassword($tempUser, $defaultPassword);
        $rows = [];
        $usedEmails = [];
        $nextId = 1;

        // Step 1: Import existing user_entity records (admin/internal users)
        $userSource = $legacy->fetchAllAssociative('SELECT * FROM user_entity WHERE entity_state_id = 1 OR entity_state_id IS NULL');

        // Real role assignments live in user_role_entity/role_entity —
        // user_entity.roles only ever holds ROLE_USER or an empty array in production
        $roleRows = $legacy->fetchAllAssociative(
            'SELECT ur.core_user_id, r.role_code
             FROM user_role_entity ur
             JOIN role_entity r ON r.id = ur.role_id
             WHERE ur.entity_state_id = 1 OR ur.entity_state_id IS NULL'
        );
        $userRoleMap = []; // core_user_id → [role_code, ...]
        foreach ($roleRows as $rr) {
            $userRoleMap[(int) $rr['core_user_id']][] = $rr['role_code'];
        }

        // Build account→user map via account_entity.owner_id (for fallback)
        $accountOwners = $legacy->fetchAllAssociative('SELECT id, owner_id FROM account_entity WHERE owner_id IS NOT NULL AND owner_id > 0');
        $ownerToAccountMap = []; // owner_user_id → account_id
        foreach ($accountOwners as $ao) {
            $ownerId = (int) $ao['owner_id'];
            $accountId = (int) $ao['id'];
            if (!isset($ownerToAccountMap[$ownerId])) {
                $ownerToAccountMap[$ownerId] = $accountId;
            }
        }

        foreach ($userSource as $row) {
            $userId = (int) $row['id'];
            $email = $row['email'] ?? 'user-' . $row['id'] . '@imported.local';

            // Parse roles — FOS User Bundle stores as PHP serialized or JSON
            $roles = ['ROLE_USER'];
            if (!empty($row['roles'])) {
                $decoded = @json_decode($row['roles'], true);
                if (is_array($decoded) && !empty($decoded)) {
                    $roles = $decoded;
                } else {
                    $decoded = @unserialize($row['roles']);
                    if (is_array($decoded) && !empty($decoded)) {
                        $roles = $decoded;
                    }
                }
            }
            $roles = $this->mapFosRoles(array_merge($roles, $userRoleMap[$userId] ?? []));

            $clientId = $ownerToAccountMap[$userId] ?? null;

            $rows[] = [
                'id' => $userId,
                'email' => $email,
                'username' => $row['username'] ?? null,
                'roles' => json_encode($roles),
                'password' => !empty($row['password']) ? $row['password'] : $hashedPassword,
                'first_name' => $row['first_name'] ?? 'Imported',
                'last_name' => $row['last_name'] ?? 'User',
                'phone_number' => null,
                'address' => null,
                'is_active' => $this->toBool($row['enabled'] ?? 1),
                'failed_login_attempts' => 0,
                'client_id' => $clientId,
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
            $usedEmails[strtolower($email)] = true;
            $this->importedIds['user'][$userId] = true;
            if ($clientId) {
                $this->accountUserMap[$clientId] = $userId;
            }
            if ($userId >= $nextId) {
                $nextId = $userId + 1;
            }
        }

        $this->io->writeln(sprintf('  Imported %d users from user_entity', count($rows)));

        // Step 2: Import production contacts as users (ones that don't already have a user_entity match)
        $contactSource = $legacy->fetchAllAssociative('SELECT * FROM contact_entity');
        $contactUsers = 0;

        foreach ($contactSource as $row) {
            $accountId = isset($row['account_id']) ? (int) $row['account_id'] : null;

            // Only import contacts for imported clients
            if ($this->storeFilter && $accountId && !isset($this->importedIds['client'][$accountId])) {
                continue;
            }

            $email = $row['email'] ?? null;

            // Skip contacts without email (can't create a user without one)
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                continue;
            }

            // Skip if this email already exists (from user_entity import)
            if (isset($usedEmails[strtolower($email)])) {
                // But ensure the existing user gets this client_id if they don't have one
                foreach ($rows as &$existingRow) {
                    if (strtolower($existingRow['email']) === strtolower($email) && !$existingRow['client_id'] && $accountId) {
                        $existingRow['client_id'] = $accountId;
                        $this->accountUserMap[$accountId] = $existingRow['id'];
                    }
                }
                unset($existingRow);
                continue;
            }

            $userId = $nextId++;
            $clientId = $accountId;

            $rows[] = [
                'id' => $userId,
                'email' => $email,
                'username' => null,
                'roles' => json_encode(['ROLE_CLIENT', 'ROLE_USER']),
                'password' => $hashedPassword,
                'first_name' => $row['first_name'] ?? 'Imported',
                'last_name' => $row['last_name'] ?? 'Contact',
                'phone_number' => $row['phone'] ?? null,
                'address' => null,
                'is_active' => $this->toBool($row['is_active'] ?? 1),
                'failed_login_attempts' => 0,
                'client_id' => $clientId,
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
            $usedEmails[strtolower($email)] = true;
            $this->importedIds['user'][$userId] = true;
            $contactUsers++;

            // Build account→user map (first user per account wins)
            if ($clientId && !isset($this->accountUserMap[$clientId])) {
                $this->accountUserMap[$clientId] = $userId;
            }
        }

        $this->io->writeln(sprintf('  Created %d users from contact_entity', $contactUsers));

        // Step 3: Add test admin users for development
        $testUsers = [
            ['email' => 'super@starlinger.com', 'username' => 'superadmin', 'roles' => ['ROLE_ADMIN', 'ROLE_USER'], 'first_name' => 'Super', 'last_name' => 'Admin'],
            ['email' => 'admin@starlinger.com', 'username' => 'admin', 'roles' => ['ROLE_ADMIN', 'ROLE_USER'], 'first_name' => 'Admin', 'last_name' => 'User'],
            ['email' => 'clientadmin@starlinger.com', 'username' => 'clientadmin', 'roles' => ['ROLE_CLIENT_ADMIN', 'ROLE_USER'], 'first_name' => 'Client', 'last_name' => 'Admin'],
            ['email' => 'recouser@starlinger.com', 'username' => 'recouser', 'roles' => ['ROLE_CLIENT', 'ROLE_USER'], 'first_name' => 'Reco', 'last_name' => 'User'],
        ];
        $testUserCount = 0;
        foreach ($testUsers as $tu) {
            if (!isset($usedEmails[strtolower($tu['email'])])) {
                $rows[] = [
                    'id' => $nextId,
                    'email' => $tu['email'],
                    'username' => $tu['username'],
                    'roles' => json_encode($tu['roles']),
                    'password' => $hashedPassword,
                    'first_name' => $tu['first_name'],
                    'last_name' => $tu['last_name'],
                    'phone_number' => null,
                    'address' => null,
                    'is_active' => true,
                    'failed_login_attempts' => 0,
                    'client_id' => null,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ];
                $usedEmails[strtolower($tu['email'])] = true;
                $nextId++;
                $testUserCount++;
            }
        }
        $this->io->writeln(sprintf('  Added %d test admin users', $testUserCount));

        $this->io->writeln(sprintf('  Total users: %d', count($rows)));
        $this->io->writeln(sprintf('  Account-User mappings: %d', count($this->accountUserMap)));
        $this->importBatch($conn, '`user`', $rows);
        $this->resetAutoIncrement($conn, '`user`');
        $this->stats['User'] = count($rows);
    }

    private function mapFosRoles(array $fosRoles): array
    {
        $recoRoles = [];
        foreach ($fosRoles as $role) {
            $role = strtoupper(trim($role));
            $recoRoles[] = match (true) {
                // COMMERCE_ADMIN must be checked before the generic ADMIN match:
                // legacy webshop staff become client admins, not backend admins
                str_contains($role, 'COMMERCE_ADMIN') => 'ROLE_CLIENT_ADMIN',
                str_contains($role, 'SUPER') => 'ROLE_ADMIN',
                str_contains($role, 'ADMIN') => 'ROLE_ADMIN',
                str_contains($role, 'MANAGER') => 'ROLE_CLIENT_ADMIN',
                default => 'ROLE_CLIENT',
            };
        }

        return array_unique($recoRoles) ?: ['ROLE_USER'];
    }

    private function importAddresses(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing Addresses');
        $source = $legacy->fetchAllAssociative('SELECT * FROM address_entity WHERE entity_state_id = 1 OR entity_state_id IS NULL');
        $rows = [];

        foreach ($source as $row) {
            $clientId = isset($row['account_id']) ? (int) $row['account_id'] : null;
            if (!$clientId) {
                continue;
            }
            // Only import addresses for imported clients
            if (!isset($this->importedIds['client'][$clientId])) {
                continue;
            }

            $city = isset($row['city_id']) && $row['city_id']
                ? ($this->cityMap[(int) $row['city_id']] ?? null)
                : null;

            $rows[] = [
                'id' => (int) $row['id'],
                'client_id' => $clientId,
                'country_id' => $city['country_id'] ?? null,
                'street' => $row['street'] ?? 'N/A',
                'city' => $city['name'] ?? 'Unknown',
                'postal_code' => $city['postal_code'] ?? null,
                'is_billing' => $this->toBool($row['billing'] ?? 0),
                'is_delivery' => $this->toBool($row['show_as_delivery'] ?? $row['default_shipping_address'] ?? 0),
                'is_active' => $this->toBool($row['active'] ?? $row['is_active'] ?? 1),
                'name' => $row['name'] ?? null,
                'phone' => $row['phone'] ?? null,
                'email' => $row['email'] ?? null,
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
            $this->importedIds['address'][(int) $row['id']] = true;
        }

        $this->io->writeln(sprintf('  Found %d addresses', count($rows)));
        $this->importBatch($conn, 'address', $rows);
        $this->resetAutoIncrement($conn, 'address');
        $this->stats['Address'] = count($rows);
    }

    // ─── Level 4: Depends on Level 3 ───────────────────────────────────

    private function importDeliveryTypes(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing DeliveryTypes');
        $source = $legacy->fetchAllAssociative('SELECT * FROM delivery_type_entity');
        $rows = [];

        foreach ($source as $row) {
            // Name may be JSON per store
            $name = $this->extractLocalizedName($row['name'] ?? null, 'DeliveryType-' . $row['id']);

            // use_as_default might be JSON or int
            $useAsDefault = false;
            if (isset($row['use_as_default'])) {
                $decoded = @json_decode($row['use_as_default'], true);
                if (is_array($decoded)) {
                    $useAsDefault = !empty($decoded[$this->preferredStore] ?? $decoded['3'] ?? false);
                } else {
                    $useAsDefault = $this->toBool($row['use_as_default']);
                }
            }

            $rows[] = [
                'id' => (int) $row['id'],
                'name' => $name,
                'short_description' => $this->extractLocalizedName($row['description'] ?? null, '') ?: null,
                'remote_code' => $row['remote_code'] ?? null,
                'remote_id' => $row['remote_id'] ?? null,
                'color' => $row['color'] ?? null,
                'max_weight' => $row['max_weight'] ?? null,
                'gross_factor' => $row['gross_factor'] ?? null,
                'hide_if_not_applicable' => $this->toBool($row['hide_if_not_applicable'] ?? 0),
                'allow_recurring_payment' => $this->toBool($row['allow_recurring_payment'] ?? 0),
                'use_as_default' => (int) $useAsDefault,
                'is_active' => $this->toBool($row['active'] ?? 1),
                'sort_order' => (int) ($row['ord'] ?? 0),
                'warehouse_id' => isset($row['warehouse_id']) && $row['warehouse_id'] ? (int) $row['warehouse_id'] : null,
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
            $this->importedIds['delivery_type'][(int) $row['id']] = true;
        }

        $this->io->writeln(sprintf('  Found %d delivery types', count($rows)));
        $this->importBatch($conn, 'delivery_type', $rows);
        $this->resetAutoIncrement($conn, 'delivery_type');
        $this->stats['DeliveryType'] = count($rows);
    }

    private function importProductGroups(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing ProductGroups');
        $source = $legacy->fetchAllAssociative('SELECT * FROM product_group_entity ORDER BY COALESCE(product_group_id, 0), id');
        $rows = [];
        $usedSlugs = [];

        foreach ($source as $row) {
            // Name may be JSON per store
            $name = $this->extractLocalizedName($row['name'] ?? null, 'Group-' . $row['id']);

            // Slug from url field (may be JSON)
            $slug = $this->extractLocalizedName($row['url'] ?? null, '');
            if (empty($slug)) {
                $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
            }
            $slug = trim($slug, '-');
            if (empty($slug)) {
                $slug = 'group-' . $row['id'];
            }
            $baseSlug = $slug;
            $i = 1;
            while (isset($usedSlugs[$slug])) {
                $slug = $baseSlug . '-' . $i++;
            }
            $usedSlugs[$slug] = true;

            $rows[] = [
                'id' => (int) $row['id'],
                'name' => $name,
                'slug' => $slug,
                'description' => $this->extractLocalizedName($row['description'] ?? null, '') ?: null,
                'product_group_code' => $row['code'] ?? null,
                'level' => (int) ($row['level'] ?? 0),
                'total_products' => (int) ($row['products_in_group'] ?? 0),
                'show_on_homepage' => $this->toBool($row['show_on_homepage'] ?? 0),
                'is_active' => $this->toBool($row['is_active'] ?? $row['active'] ?? 1),
                'sort_order' => (int) ($row['ord'] ?? 0),
                'meta_title' => $this->extractLocalizedName($row['meta_title'] ?? null, '') ?: null,
                'meta_description' => $this->extractLocalizedName($row['meta_description'] ?? null, '') ?: null,
                'meta_keywords' => $this->extractLocalizedName($row['meta_keywords'] ?? null, '') ?: null,
                'parent_id' => isset($row['product_group_id']) && $row['product_group_id'] ? (int) $row['product_group_id'] : null,
                'featured_image_id' => null,
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
            $this->importedIds['product_group'][(int) $row['id']] = true;
        }

        $this->io->writeln(sprintf('  Found %d product groups', count($rows)));
        $this->importBatch($conn, 'product_group', $rows);
        $this->resetAutoIncrement($conn, 'product_group');
        $this->stats['ProductGroup'] = count($rows);
    }

    // ─── Level 5: Depends on Level 4 ───────────────────────────────────

    private function importDeliveryPrices(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing DeliveryPrices');
        $source = $legacy->fetchAllAssociative('SELECT * FROM delivery_prices_entity');
        $rows = [];

        foreach ($source as $row) {
            $deliveryTypeId = isset($row['delivery_id']) && $row['delivery_id'] ? (int) $row['delivery_id'] : null;
            if (!$deliveryTypeId) {
                continue; // FK required
            }

            $rows[] = [
                'id' => (int) $row['id'],
                'delivery_type_id' => $deliveryTypeId,
                'name' => $row['name'] ?? null,
                'dhl_zone' => isset($row['dhl_zone_id']) && $row['dhl_zone_id'] ? (int) $row['dhl_zone_id'] : null,
                'postal_code_from' => $row['postal_code_from'] ?? null,
                'postal_code_to' => $row['postal_code_to'] ?? null,
                'exclude_postal_codes' => $row['exclude_postal_codes'] ?? null,
                'size_from' => $row['size_from'] ?? null,
                'size_to' => $row['size_to'] ?? null,
                'price_base' => $row['price_base'] ?? '0.00',
                'delivery_days' => isset($row['delivery_days']) ? (int) $row['delivery_days'] : null,
                'step_starts_at' => $row['step_starts_at'] ?? null,
                'for_every_next_size' => $row['for_every_next_size'] ?? null,
                'price_base_step' => $row['price_base_step'] ?? null,
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
        }

        $this->io->writeln(sprintf('  Found %d delivery prices', count($rows)));
        $this->importBatch($conn, 'delivery_price', $rows);
        $this->resetAutoIncrement($conn, 'delivery_price');
        $this->stats['DeliveryPrice'] = count($rows);
    }

    private function importFuelSurcharges(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing FuelSurcharges');
        $source = $legacy->fetchAllAssociative('SELECT * FROM fuel_surcharge_entity');
        $rows = [];

        foreach ($source as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'delivery_type_id' => isset($row['delivery_type_id']) && $row['delivery_type_id'] ? (int) $row['delivery_type_id'] : null,
                'name' => null, // Not in legacy schema
                'date' => $row['date'] ?? null,
                'fuel_surcharge' => $row['fuel_surcharge'] ?? null,
                'size_from' => null,
                'size_to' => null,
                'price_base' => null,
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
        }

        $this->io->writeln(sprintf('  Found %d fuel surcharges', count($rows)));
        $this->importBatch($conn, 'fuel_surcharge', $rows);
        $this->resetAutoIncrement($conn, 'fuel_surcharge');
        $this->stats['FuelSurcharge'] = count($rows);
    }

    private function importProducts(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing Products');

        $offset = 0;
        $total = 0;
        $batch = [];
        $usedSlugs = [];

        while (true) {
            $source = $legacy->fetchAllAssociative(
                "SELECT * FROM product_entity ORDER BY id LIMIT {$this->batchSize} OFFSET {$offset}"
            );

            if (empty($source)) {
                break;
            }

            foreach ($source as $row) {
                if (!$this->matchesStoreFilter($row['show_on_store'] ?? null)) {
                    continue;
                }

                $currencyCode = 'EUR';
                if (isset($row['currency_id']) && $row['currency_id']) {
                    $currencyCode = $this->currencyMap[(int) $row['currency_id']] ?? 'EUR';
                }

                // Product name is JSON per store: {"3":"English","6":"German"}
                $name = $this->extractLocalizedName($row['name'] ?? null, 'Product-' . $row['id']);

                // Short description may also be JSON
                $shortDesc = $this->extractLocalizedName($row['short_description'] ?? null, '');
                if ($shortDesc === '') $shortDesc = null;

                // Technical description may be JSON
                $techDesc = $this->extractLocalizedName($row['description'] ?? null, '');
                if ($techDesc === '') $techDesc = null;

                // Slug from url field (may also be JSON)
                $slug = $this->extractLocalizedName($row['url'] ?? null, '');
                if (empty($slug)) {
                    $slug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
                }
                $slug = trim($slug, '-');
                if (empty($slug)) {
                    $slug = 'product-' . $row['id'];
                }
                // Ensure uniqueness
                $baseSlug = $slug;
                $i = 1;
                while (isset($usedSlugs[$slug])) {
                    $slug = $baseSlug . '-' . $i++;
                }
                $usedSlugs[$slug] = true;

                // Weight is decimal in production, string in RECO
                $weight = null;
                if (isset($row['weight']) && $row['weight'] !== null && $row['weight'] !== '0.0000') {
                    $weight = rtrim(rtrim(sprintf('%.2f', $row['weight']), '0'), '.') . ' kg';
                }

                $batch[] = [
                    'id' => (int) $row['id'],
                    'name' => $name,
                    'slug' => $slug,
                    'part_no' => $row['code'] ?? null,
                    'short_description' => $shortDesc,
                    'unit' => $row['measure'] ?? null,
                    'price' => (float) ($row['price_base'] ?? 0),
                    'weight' => $weight,
                    'technical_description' => $techDesc,
                    'machine_text' => null,
                    'statistic' => null,
                    'is_active' => $this->toBool($row['active'] ?? 1),
                    'qty' => isset($row['qty']) ? (int) (float) $row['qty'] : null,
                    'qty_step' => isset($row['qty_step']) ? (int) (float) $row['qty_step'] : null,
                    'quote_item_limit' => isset($row['quote_item_limit']) ? (int) (float) $row['quote_item_limit'] : null,
                    'fixed_qty' => isset($row['fixed_qty']) ? (int) (float) $row['fixed_qty'] : null,
                    'product_group_id' => isset($row['product_groups_id']) && $row['product_groups_id'] ? (int) $row['product_groups_id'] : null,
                    'catalog_code' => $row['catalog_code'] ?? null,
                    'currency' => $currencyCode,
                    'featured_image_id' => null,
                    'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                    'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
                ];
                $this->importedIds['product'][(int) $row['id']] = true;
                $total++;
            }

            if (!empty($batch)) {
                $this->importBatch($conn, 'product', $batch);
                $batch = [];
            }

            $offset += $this->batchSize;
            $this->io->writeln(sprintf('  Processed %d rows...', $offset));
        }

        $this->resetAutoIncrement($conn, 'product');
        $this->io->writeln(sprintf('  Imported %d products', $total));
        $this->stats['Product'] = $total;
    }

    // ─── Level 6: Depends on Level 5 ───────────────────────────────────

    private function importProductProductLinks(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing ProductProductLinks');
        $source = $legacy->fetchAllAssociative('SELECT * FROM product_product_link_entity');
        $rows = [];

        foreach ($source as $row) {
            $parentId = isset($row['parent_product_id']) ? (int) $row['parent_product_id'] :
                        (isset($row['product_id']) ? (int) $row['product_id'] : null);
            $childId = isset($row['child_product_id']) ? (int) $row['child_product_id'] :
                       (isset($row['linked_product_id']) ? (int) $row['linked_product_id'] : null);

            // Only import links for imported products
            if ($this->storeFilter) {
                if ($parentId && !isset($this->importedIds['product'][$parentId])) continue;
                if ($childId && !isset($this->importedIds['product'][$childId])) continue;
            }

            $rows[] = [
                'id' => (int) $row['id'],
                'parent_product_id' => $parentId,
                'child_product_id' => $childId,
                'relation_type_id' => isset($row['relation_type_id']) ? (int) $row['relation_type_id'] :
                                      (isset($row['type_id']) ? (int) $row['type_id'] : null),
                'ord' => isset($row['ord']) ? (int) $row['ord'] : null,
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
        }

        $this->io->writeln(sprintf('  Found %d product links (filtered)', count($rows)));
        $this->importBatch($conn, 'product_product_link', $rows);
        $this->resetAutoIncrement($conn, 'product_product_link');
        $this->stats['ProductProductLink'] = count($rows);
    }

    private function importClientProductPrices(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing ClientProductPrices');

        $offset = 0;
        $total = 0;
        $batch = [];

        while (true) {
            $source = $legacy->fetchAllAssociative(
                "SELECT * FROM product_account_price_entity ORDER BY id LIMIT {$this->batchSize} OFFSET {$offset}"
            );

            if (empty($source)) {
                break;
            }

            foreach ($source as $row) {
                $clientId = isset($row['account_id']) ? (int) $row['account_id'] : (isset($row['client_id']) ? (int) $row['client_id'] : null);
                $productId = isset($row['product_id']) ? (int) $row['product_id'] : null;

                if (!$clientId || !$productId) continue;

                // Filter by imported entities
                if ($this->storeFilter) {
                    if (!isset($this->importedIds['client'][$clientId])) continue;
                    if (!isset($this->importedIds['product'][$productId])) continue;
                }

                $batch[] = [
                    'id' => (int) $row['id'],
                    'client_id' => $clientId,
                    'product_id' => $productId,
                    // The actual per-client price lives in discount_price_base;
                    // price_base is NULL for every row in production
                    'price' => (float) ($row['discount_price_base'] ?? $row['price_base'] ?? 0),
                    'discount_percentage' => isset($row['discount_percentage']) ? (float) $row['discount_percentage'] :
                                             (isset($row['discount']) ? (float) $row['discount'] : null),
                    'valid_from' => $row['valid_from'] ?? $row['date_valid_from'] ?? null,
                    'valid_until' => $row['valid_until'] ?? $row['date_valid_to'] ?? null,
                    'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                    'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
                ];
                $total++;
            }

            if (!empty($batch)) {
                $this->importBatch($conn, 'client_product_price', $batch);
                $batch = [];
            }

            $offset += $this->batchSize;
            if ($offset % 1000 === 0) {
                $this->io->writeln(sprintf('  Processed %d rows...', $offset));
            }
        }

        $this->resetAutoIncrement($conn, 'client_product_price');
        $this->io->writeln(sprintf('  Imported %d client product prices', $total));
        $this->stats['ClientProductPrice'] = $total;
    }

    private function importOrders(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing Orders');
        $source = $legacy->fetchAllAssociative('SELECT * FROM order_entity');
        $rows = [];
        $skippedNoUser = 0;

        // DHL shipment tracking numbers live in dhl_parcel_entity (order_id → tracking number)
        $trackingMap = [];
        try {
            $parcels = $legacy->fetchAllAssociative(
                "SELECT order_id, shipment_tracking_number FROM dhl_parcel_entity
                 WHERE (entity_state_id = 1 OR entity_state_id IS NULL)
                   AND shipment_tracking_number IS NOT NULL AND shipment_tracking_number <> ''
                   AND order_id IS NOT NULL"
            );
            foreach ($parcels as $p) {
                $trackingMap[(int) $p['order_id']] = $p['shipment_tracking_number'];
            }
            $this->io->writeln(sprintf('  DHL tracking numbers found: %d', count($trackingMap)));
        } catch (\Exception $e) {
            $this->io->note('dhl_parcel_entity not found in production, orders imported without tracking');
        }

        foreach ($source as $row) {
            // Orders have account_id, not user_id — map via accountUserMap
            $accountId = isset($row['account_id']) ? (int) $row['account_id'] : null;
            $userId = $this->accountUserMap[$accountId] ?? null;

            if (!$userId) {
                // Try created_by as username → look up user
                $skippedNoUser++;
                continue; // Can't create order without user (FK constraint)
            }

            $status = 'pending';
            if (isset($row['order_state_id'])) {
                $status = $this->orderStateMap[(int) $row['order_state_id']] ?? 'pending';
            }

            // Compose "street, city" — the app's canonical order-address format;
            // the admin UI matches order addresses against client addresses by street AND city
            $shippingAddr = $row['account_shipping_street'] ?? null;
            $shipCity = !empty($row['account_shipping_city_id'])
                ? ($this->cityMap[(int) $row['account_shipping_city_id']]['name'] ?? null)
                : null;
            if ($shippingAddr && $shipCity) {
                $shippingAddr .= ', ' . $shipCity;
            }

            $billingAddr = $row['account_billing_street'] ?? null;
            $billCity = !empty($row['account_billing_city_id'])
                ? ($this->cityMap[(int) $row['account_billing_city_id']]['name'] ?? null)
                : null;
            if ($billingAddr && $billCity) {
                $billingAddr .= ', ' . $billCity;
            }

            // Snapshot address FK — only keep it if that address was actually imported
            // (legacy may reference inactive addresses that were filtered out)
            $shippingAddressId = !empty($row['account_shipping_address_id']) ? (int) $row['account_shipping_address_id'] : null;
            if ($shippingAddressId && !isset($this->importedIds['address'][$shippingAddressId])) {
                $shippingAddressId = null;
            }

            $rows[] = [
                'id' => (int) $row['id'],
                'user_id' => $userId,
                'order_number' => $row['increment_id'] ? (string) $row['increment_id'] : 'ORD-' . $row['id'],
                'status' => $status,
                'total_amount' => (float) ($row['base_price_total'] ?? $row['price_total'] ?? 0),
                'subtotal_before_discount' => (float) ($row['base_price_without_tax'] ?? 0),
                'total_discount' => (float) ($row['base_price_discount'] ?? 0),
                'total_tax' => (float) ($row['base_price_tax'] ?? 0),
                'payment_type_id' => !empty($row['payment_type_id']) ? (int) $row['payment_type_id'] : null,
                'delivery_type_id' => !empty($row['delivery_type_id']) ? (int) $row['delivery_type_id'] : null,
                'shipping_address_id' => $shippingAddressId,
                'notes' => null,
                'shipping_address' => $shippingAddr,
                'billing_address' => $billingAddr,
                'is_draft' => (int) ($status === 'draft'),
                'tracking_number' => $trackingMap[(int) $row['id']] ?? null,
                'tracking_carrier' => isset($trackingMap[(int) $row['id']]) ? 'dhl' : null,
                'tracking_url' => isset($trackingMap[(int) $row['id']])
                    ? 'https://www.dhl.com/en/express/tracking.html?AWB=' . $trackingMap[(int) $row['id']]
                    : null,
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
            $this->importedIds['order'][(int) $row['id']] = true;
        }

        if ($skippedNoUser > 0) {
            $this->io->warning(sprintf('Skipped %d orders with no mapped user', $skippedNoUser));
        }

        $this->io->writeln(sprintf('  Found %d orders', count($rows)));
        $this->importBatch($conn, '`order`', $rows);
        $this->resetAutoIncrement($conn, '`order`');
        $this->stats['Order'] = count($rows);
    }

    private function importOrderItems(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing OrderItems');
        $source = $legacy->fetchAllAssociative('SELECT * FROM order_item_entity');
        $rows = [];

        foreach ($source as $row) {
            $orderId = isset($row['order_id']) ? (int) $row['order_id'] : null;
            $productId = isset($row['product_id']) ? (int) $row['product_id'] : null;

            if (!$orderId || !$productId) continue;

            // Only import items for imported orders and products
            if (!isset($this->importedIds['order'][$orderId])) continue;
            if (!isset($this->importedIds['product'][$productId])) continue;

            $qty = (int) (float) ($row['qty'] ?? 1);
            if ($qty < 1) $qty = 1;
            $unitPrice = (float) ($row['base_price_item'] ?? $row['price_item'] ?? 0);
            $subtotal = (float) ($row['base_price_total'] ?? ($qty * $unitPrice));

            $rows[] = [
                'id' => (int) $row['id'],
                'order_ref_id' => $orderId,
                'product_id' => $productId,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'subtotal' => $subtotal,
                'is_custom_price' => 0,
                'original_unit_price' => isset($row['original_price_item']) ? (float) $row['original_price_item'] : null,
                'discount_percent' => (float) ($row['percentage_discount'] ?? 0),
                'tax_percent' => !empty($row['tax_type_id']) ? ($this->taxTypeMap[(int) $row['tax_type_id']] ?? 0) : 0,
                'tax_amount' => (float) ($row['base_price_tax'] ?? 0),
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
        }

        $this->io->writeln(sprintf('  Found %d order items (filtered)', count($rows)));
        $this->importBatch($conn, 'order_item', $rows);
        $this->resetAutoIncrement($conn, 'order_item');
        $this->stats['OrderItem'] = count($rows);
    }

    private function importOrderLogs(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing OrderLogs');

        // Order logs might not exist in legacy — check first
        try {
            $source = $legacy->fetchAllAssociative('SELECT * FROM order_log_entity');
        } catch (\Exception $e) {
            $this->io->note('order_log_entity not found in production, skipping');
            $this->stats['OrderLog'] = 0;
            return;
        }

        $rows = [];
        foreach ($source as $row) {
            $orderId = isset($row['order_id']) ? (int) $row['order_id'] : null;
            if (!$orderId) continue;

            if ($this->storeFilter && !isset($this->importedIds['order'][$orderId])) {
                continue;
            }

            $rows[] = [
                'id' => (int) $row['id'],
                'order_id' => $orderId,
                'changed_by_id' => isset($row['changed_by_id']) ? (int) $row['changed_by_id'] : null,
                'previous_status' => $row['previous_status'] ?? '',
                'new_status' => $row['new_status'] ?? '',
                'comment' => $row['comment'] ?? null,
                'metadata' => !empty($row['metadata']) ? $row['metadata'] : null,
                'created_at' => $row['created'] ?? $row['created_at'] ?? date('Y-m-d H:i:s'),
            ];
        }

        $this->io->writeln(sprintf('  Found %d order logs (filtered)', count($rows)));
        $this->importBatch($conn, 'order_log', $rows);
        $this->resetAutoIncrement($conn, 'order_log');
        $this->stats['OrderLog'] = count($rows);
    }

    private function importProductDiscounts(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing ProductDiscounts');

        try {
            $source = $legacy->fetchAllAssociative('SELECT * FROM product_discount_entity');
        } catch (\Exception $e) {
            $this->io->note('product_discount_entity not found in production, skipping');
            $this->stats['ProductDiscount'] = 0;
            return;
        }

        $rows = [];
        foreach ($source as $row) {
            $productId = isset($row['product_id']) ? (int) $row['product_id'] : null;
            if ($this->storeFilter && $productId && !isset($this->importedIds['product'][$productId])) {
                continue;
            }

            $rows[] = [
                'id' => (int) $row['id'],
                'product_id' => $productId,
                'discount_price_base' => $row['discount_price_base'] ?? null,
                'discount_price_retail' => $row['discount_price_retail'] ?? null,
                'rebate' => $row['rebate'] ?? null,
                'type' => isset($row['type']) ? (int) $row['type'] : null,
                'date_valid_from' => $row['date_valid_from'] ?? null,
                'date_valid_to' => $row['date_valid_to'] ?? null,
                'applied_to' => $row['applied_to'] ?? null,
                'created_at' => $row['created'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? date('Y-m-d H:i:s'),
            ];
        }

        $this->io->writeln(sprintf('  Found %d product discounts (filtered)', count($rows)));
        $this->importBatch($conn, 'product_discount', $rows);
        $this->resetAutoIncrement($conn, 'product_discount');
        $this->stats['ProductDiscount'] = count($rows);
    }

    private function importImportManuals(Connection $legacy, Connection $conn, string $defaultPassword): void
    {
        $this->io->section('Importing ImportManuals');
        $source = $legacy->fetchAllAssociative('SELECT * FROM import_manual_entity');
        $rows = [];

        foreach ($source as $row) {
            $rows[] = [
                'id' => (int) $row['id'],
                'type_id' => !empty($row['import_manual_type_id']) ? (int) $row['import_manual_type_id'] : null,
                'status_id' => !empty($row['import_manual_status_id']) ? (int) $row['import_manual_status_id'] : null,
                'user_id' => isset($row['user_id']) ? (int) $row['user_id'] : null,
                'file' => $row['file'] ?? null,
                'filename' => $row['filename'] ?? null,
                'file_type' => $row['file_type'] ?? null,
                'size' => $row['size'] ?? null,
                'file_source' => $row['file_source'] ?? null,
                'date_started' => $row['date_started'] ?? null,
                'date_finished' => $row['date_finished'] ?? null,
                'import_result' => $row['import_result'] ?? null,
                'rows_imported' => isset($row['rows_imported']) ? (int) $row['rows_imported'] : null,
                'input_parameters' => $row['input_parameters'] ?? null,
                'created_at' => $row['created'] ?? $row['created_at'] ?? date('Y-m-d H:i:s'),
                'updated_at' => $row['modified'] ?? $row['updated_at'] ?? date('Y-m-d H:i:s'),
                'created_by' => $row['created_by'] ?? null,
                'modified_by' => $row['modified_by'] ?? null,
            ];
        }

        $this->io->writeln(sprintf('  Found %d import manuals', count($rows)));
        $this->importBatch($conn, 'import_manual_entity', $rows);
        $this->resetAutoIncrement($conn, 'import_manual_entity');
        $this->stats['ImportManual'] = count($rows);
    }
}
