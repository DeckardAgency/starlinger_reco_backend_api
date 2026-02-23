<?php

namespace App\Command;

use App\Entity\Client;
use App\Entity\Country;
use App\Entity\DeliveryPrice;
use App\Entity\DeliveryType;
use App\Entity\PaymentType;
use App\Entity\Product;
use App\Entity\ProductGroup;
use App\Entity\TaxType;
use App\Entity\User;
use App\Entity\Warehouse;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:load-test-data',
    description: 'Load comprehensive test data for RECO frontend development',
)]
class LoadTestDataCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly UserPasswordHasherInterface $passwordHasher,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('reset', 'r', InputOption::VALUE_NONE, 'Clear existing test data before loading')
            ->addOption('skip-products', null, InputOption::VALUE_NONE, 'Skip loading products (use if already imported)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Loading RECO Test Data');

        $skipProducts = $input->getOption('skip-products');

        try {
            // 1. Load Tax Types
            $taxTypes = $this->loadTaxTypes($io);

            // 2. Load Countries
            $countries = $this->loadCountries($io, $taxTypes);

            // 3. Load Warehouses
            $warehouses = $this->loadWarehouses($io, $countries);

            // 4. Load Payment Types
            $paymentTypes = $this->loadPaymentTypes($io);

            // 5. Load Delivery Types
            $deliveryTypes = $this->loadDeliveryTypes($io, $warehouses);

            // 6. Load Delivery Prices
            $this->loadDeliveryPrices($io, $deliveryTypes);

            // 7. Load Product Groups
            $productGroups = $this->loadProductGroups($io);

            // 8. Load Test Client
            $client = $this->loadTestClient($io, $countries);

            // 9. Load Test User
            $user = $this->loadTestUser($io, $client);

            // 10. Load Products (if not skipped)
            if (!$skipProducts) {
                $this->loadProducts($io);
            } else {
                $io->note('Skipping products (--skip-products)');
            }

            $this->entityManager->flush();

            // Summary
            $io->section('Test Data Loaded Successfully!');
            $io->table(
                ['Entity', 'Count'],
                [
                    ['Tax Types', count($taxTypes)],
                    ['Countries', count($countries)],
                    ['Warehouses', count($warehouses)],
                    ['Payment Types', count($paymentTypes)],
                    ['Delivery Types', count($deliveryTypes)],
                    ['Product Groups', count($productGroups)],
                    ['Clients', 1],
                    ['Users', 5],
                    ['Products', $skipProducts ? 'skipped' : '30'],
                ]
            );

            $io->section('Test Credentials (Password: recouser123!)');
            $io->table(
                ['Email', 'Role', 'Description'],
                [
                    ['super@starlinger.com', 'ROLE_ADMIN', 'Full system access'],
                    ['admin@starlinger.com', 'ROLE_ADMIN', 'Admin access'],
                    ['clientadmin@starlinger.com', 'ROLE_CLIENT_ADMIN', 'Client administrator'],
                    ['recouser@starlinger.com', 'ROLE_CLIENT', 'Customer user (default)'],
                ]
            );
            $io->note('Client for client roles: Starlinger Development (STL-DEV)');

            $io->section('API Endpoints');
            $io->listing([
                'Auth: POST /api/login_check {"username": "recouser@starlinger.com", "password": "recouser123!"}',
                'Products: GET /api/v1/products',
                'Countries: GET /api/v1/countries',
                'Product Groups: GET /api/v1/product_groups',
            ]);

            return Command::SUCCESS;

        } catch (\Exception $e) {
            $io->error(['Failed to load test data:', $e->getMessage(), $e->getTraceAsString()]);
            return Command::FAILURE;
        }
    }

    private function loadTaxTypes(SymfonyStyle $io): array
    {
        $io->section('Loading Tax Types');

        $taxTypesData = [
            ['name' => 'Standard VAT 20%', 'percent' => '20.00', 'remoteCode' => 'VAT20'],
            ['name' => 'Reduced VAT 10%', 'percent' => '10.00', 'remoteCode' => 'VAT10'],
            ['name' => 'Zero Rate', 'percent' => '0.00', 'remoteCode' => 'VAT0'],
            ['name' => 'Export (No VAT)', 'percent' => '0.00', 'remoteCode' => 'EXPORT'],
        ];

        $taxTypes = [];
        foreach ($taxTypesData as $data) {
            $existing = $this->entityManager->getRepository(TaxType::class)->findOneBy(['remoteCode' => $data['remoteCode']]);
            if ($existing) {
                $taxTypes[$data['remoteCode']] = $existing;
                continue;
            }

            $taxType = new TaxType();
            $taxType->setName($data['name']);
            $taxType->setPercent($data['percent']);
            $taxType->setRemoteCode($data['remoteCode']);
            $taxType->setIsActive(true);
            $this->entityManager->persist($taxType);
            $taxTypes[$data['remoteCode']] = $taxType;
        }

        $this->entityManager->flush();
        $io->success(sprintf('Loaded %d tax types', count($taxTypes)));

        return $taxTypes;
    }

    private function loadCountries(SymfonyStyle $io, array $taxTypes): array
    {
        $io->section('Loading Countries');

        $countriesData = [
            ['name' => 'Austria', 'code' => 'AT', 'alpha3' => 'AUT', 'eu' => true, 'dhlZone' => 1],
            ['name' => 'Germany', 'code' => 'DE', 'alpha3' => 'DEU', 'eu' => true, 'dhlZone' => 1],
            ['name' => 'Switzerland', 'code' => 'CH', 'alpha3' => 'CHE', 'eu' => false, 'dhlZone' => 2],
            ['name' => 'United Kingdom', 'code' => 'GB', 'alpha3' => 'GBR', 'eu' => false, 'dhlZone' => 2],
            ['name' => 'United States', 'code' => 'US', 'alpha3' => 'USA', 'eu' => false, 'dhlZone' => 5],
            ['name' => 'China', 'code' => 'CN', 'alpha3' => 'CHN', 'eu' => false, 'dhlZone' => 6],
            ['name' => 'Japan', 'code' => 'JP', 'alpha3' => 'JPN', 'eu' => false, 'dhlZone' => 6],
            ['name' => 'Australia', 'code' => 'AU', 'alpha3' => 'AUS', 'eu' => false, 'dhlZone' => 7],
            ['name' => 'India', 'code' => 'IN', 'alpha3' => 'IND', 'eu' => false, 'dhlZone' => 6],
            ['name' => 'Brazil', 'code' => 'BR', 'alpha3' => 'BRA', 'eu' => false, 'dhlZone' => 8],
        ];

        $countries = [];
        foreach ($countriesData as $data) {
            $existing = $this->entityManager->getRepository(Country::class)->findOneBy(['code' => $data['code']]);
            if ($existing) {
                $countries[$data['code']] = $existing;
                continue;
            }

            $country = new Country();
            $country->setName($data['name']);
            $country->setCode($data['code']);
            $country->setIso31661Alpha3Code($data['alpha3']);
            $country->setEuropeanUnion($data['eu']);
            $country->setDhlZone($data['dhlZone']);
            $country->setDefaultTaxPercent($data['eu'] ? '20.00' : '0.00');
            $country->setTaxType($data['eu'] ? $taxTypes['VAT20'] : $taxTypes['EXPORT']);
            $country->setIsActive(true);
            $this->entityManager->persist($country);
            $countries[$data['code']] = $country;
        }

        $this->entityManager->flush();
        $io->success(sprintf('Loaded %d countries', count($countries)));

        return $countries;
    }

    private function loadWarehouses(SymfonyStyle $io, array $countries): array
    {
        $io->section('Loading Warehouses');

        $warehousesData = [
            [
                'name' => 'Starlinger Headquarters',
                'code' => 'WH-AT-01',
                'address' => 'Sonnenuhrgasse 4',
                'city' => 'Vienna',
                'country' => 'AT',
                'email' => 'warehouse@starlinger.com',
                'phone' => '+43 1 599 55 0',
            ],
            [
                'name' => 'European Distribution Center',
                'code' => 'WH-DE-01',
                'address' => 'Industriestraße 15',
                'city' => 'Munich',
                'country' => 'DE',
                'email' => 'munich@starlinger.com',
                'phone' => '+49 89 123 4567',
            ],
        ];

        $warehouses = [];
        foreach ($warehousesData as $data) {
            $existing = $this->entityManager->getRepository(Warehouse::class)->findOneBy(['code' => $data['code']]);
            if ($existing) {
                $warehouses[$data['code']] = $existing;
                continue;
            }

            $warehouse = new Warehouse();
            $warehouse->setName($data['name']);
            $warehouse->setCode($data['code']);
            $warehouse->setAddress($data['address']);
            $warehouse->setCity($data['city']);
            $warehouse->setCountry($countries[$data['country']] ?? null);
            $warehouse->setEmail($data['email']);
            $warehouse->setPhone($data['phone']);
            $warehouse->setIsActive(true);
            $warehouse->setShowAsLocation(true);
            $this->entityManager->persist($warehouse);
            $warehouses[$data['code']] = $warehouse;
        }

        $this->entityManager->flush();
        $io->success(sprintf('Loaded %d warehouses', count($warehouses)));

        return $warehouses;
    }

    private function loadPaymentTypes(SymfonyStyle $io): array
    {
        $io->section('Loading Payment Types');

        $paymentTypesData = [
            ['name' => 'Invoice (Net 30)', 'code' => 'INVOICE_30', 'description' => 'Payment due within 30 days'],
            ['name' => 'Invoice (Net 60)', 'code' => 'INVOICE_60', 'description' => 'Payment due within 60 days'],
            ['name' => 'Prepayment', 'code' => 'PREPAY', 'description' => 'Full payment required before shipping'],
            ['name' => 'Letter of Credit', 'code' => 'LC', 'description' => 'Payment via Letter of Credit'],
        ];

        $paymentTypes = [];
        foreach ($paymentTypesData as $data) {
            $existing = $this->entityManager->getRepository(PaymentType::class)->findOneBy(['remoteCode' => $data['code']]);
            if ($existing) {
                $paymentTypes[$data['code']] = $existing;
                continue;
            }

            $paymentType = new PaymentType();
            $paymentType->setName($data['name']);
            $paymentType->setRemoteCode($data['code']);
            $paymentType->setShortDescription($data['description']);
            $paymentType->setIsActive(true);
            $paymentType->setUseAsDefault($data['code'] === 'INVOICE_30');
            $this->entityManager->persist($paymentType);
            $paymentTypes[$data['code']] = $paymentType;
        }

        $this->entityManager->flush();
        $io->success(sprintf('Loaded %d payment types', count($paymentTypes)));

        return $paymentTypes;
    }

    private function loadDeliveryTypes(SymfonyStyle $io, array $warehouses): array
    {
        $io->section('Loading Delivery Types');

        $deliveryTypesData = [
            ['name' => 'DHL Express', 'code' => 'DHL_EXP', 'description' => 'Express delivery (1-3 days)'],
            ['name' => 'DHL Standard', 'code' => 'DHL_STD', 'description' => 'Standard delivery (5-7 days)'],
            ['name' => 'Sea Freight', 'code' => 'SEA', 'description' => 'Ocean freight (4-6 weeks)'],
            ['name' => 'Air Freight', 'code' => 'AIR', 'description' => 'Air freight (3-5 days)'],
            ['name' => 'Pickup', 'code' => 'PICKUP', 'description' => 'Customer pickup from warehouse', 'isDelivery' => false],
        ];

        $deliveryTypes = [];
        $warehouseKeys = array_keys($warehouses);
        $defaultWarehouse = !empty($warehouseKeys) ? $warehouses[$warehouseKeys[0]] : null;

        foreach ($deliveryTypesData as $index => $data) {
            $existing = $this->entityManager->getRepository(DeliveryType::class)->findOneBy(['remoteCode' => $data['code']]);
            if ($existing) {
                $deliveryTypes[$data['code']] = $existing;
                continue;
            }

            $deliveryType = new DeliveryType();
            $deliveryType->setName($data['name']);
            $deliveryType->setRemoteCode($data['code']);
            $deliveryType->setShortDescription($data['description']);
            $deliveryType->setIsDelivery($data['isDelivery'] ?? true);
            $deliveryType->setIsActive(true);
            $deliveryType->setSortOrder($index);
            $deliveryType->setUseAsDefault($data['code'] === 'DHL_STD');
            if ($defaultWarehouse) {
                $deliveryType->setWarehouse($defaultWarehouse);
            }
            $this->entityManager->persist($deliveryType);
            $deliveryTypes[$data['code']] = $deliveryType;
        }

        $this->entityManager->flush();
        $io->success(sprintf('Loaded %d delivery types', count($deliveryTypes)));

        return $deliveryTypes;
    }

    private function loadDeliveryPrices(SymfonyStyle $io, array $deliveryTypes): void
    {
        $io->section('Loading Delivery Prices');

        $dhlStd = $deliveryTypes['DHL_STD'] ?? null;
        $dhlExp = $deliveryTypes['DHL_EXP'] ?? null;

        if (!$dhlStd && !$dhlExp) {
            $io->warning('No delivery types found, skipping delivery prices');
            return;
        }

        $count = 0;

        // DHL Standard prices by zone and weight
        if ($dhlStd) {
            $pricesData = [
                ['zone' => 1, 'from' => 0, 'to' => 5, 'price' => 15.00],
                ['zone' => 1, 'from' => 5, 'to' => 10, 'price' => 25.00],
                ['zone' => 1, 'from' => 10, 'to' => 20, 'price' => 40.00],
                ['zone' => 2, 'from' => 0, 'to' => 5, 'price' => 25.00],
                ['zone' => 2, 'from' => 5, 'to' => 10, 'price' => 45.00],
                ['zone' => 5, 'from' => 0, 'to' => 5, 'price' => 55.00],
                ['zone' => 5, 'from' => 5, 'to' => 10, 'price' => 95.00],
            ];

            foreach ($pricesData as $data) {
                $existing = $this->entityManager->getRepository(DeliveryPrice::class)
                    ->findOneBy(['deliveryType' => $dhlStd, 'dhlZone' => $data['zone'], 'sizeFrom' => $data['from']]);
                if ($existing) continue;

                $price = new DeliveryPrice();
                $price->setDeliveryType($dhlStd);
                $price->setDhlZone($data['zone']);
                $price->setSizeFrom((string) $data['from']);
                $price->setSizeTo((string) $data['to']);
                $price->setPriceBase((string) $data['price']);
                $price->setDeliveryDays(5);
                $this->entityManager->persist($price);
                $count++;
            }
        }

        $this->entityManager->flush();
        $io->success(sprintf('Loaded %d delivery prices', $count));
    }

    private function loadProductGroups(SymfonyStyle $io): array
    {
        $io->section('Loading Product Groups');

        $groupsData = [
            ['name' => 'Spare Parts', 'code' => 'SPARE', 'description' => 'Machine spare parts and components'],
            ['name' => 'Wearing Parts', 'code' => 'WEAR', 'description' => 'Parts subject to regular wear'],
            ['name' => 'Electrical Components', 'code' => 'ELEC', 'description' => 'Electrical parts and sensors'],
            ['name' => 'Hydraulic Parts', 'code' => 'HYDR', 'description' => 'Hydraulic system components'],
            ['name' => 'Filters', 'code' => 'FILT', 'description' => 'All types of filters'],
            ['name' => 'Accessories', 'code' => 'ACC', 'description' => 'Machine accessories'],
        ];

        $groups = [];
        foreach ($groupsData as $index => $data) {
            $existing = $this->entityManager->getRepository(ProductGroup::class)->findOneBy(['productGroupCode' => $data['code']]);
            if ($existing) {
                $groups[$data['code']] = $existing;
                continue;
            }

            $group = new ProductGroup();
            $group->setName($data['name']);
            $group->setProductGroupCode($data['code']);
            $group->setDescription($data['description']);
            $group->setIsActive(true);
            $group->setSortOrder($index);
            $group->setShowOnHomepage(true);
            $this->entityManager->persist($group);
            $groups[$data['code']] = $group;
        }

        $this->entityManager->flush();
        $io->success(sprintf('Loaded %d product groups', count($groups)));

        return $groups;
    }

    private function loadTestClient(SymfonyStyle $io, array $countries): Client
    {
        $io->section('Loading Test Client');

        $existing = $this->entityManager->getRepository(Client::class)->findOneBy(['code' => 'STL-DEV']);
        if ($existing) {
            $io->note('Test client already exists');
            return $existing;
        }

        $client = new Client();
        $client->setName('Starlinger Development');
        $client->setCode('STL-DEV');
        $client->setDescription('Development and testing client');
        $client->setAddress('Sonnenuhrgasse 4, 1060 Vienna, Austria');
        $client->setPhoneNumber('+43 1 599 55 0');
        $client->setEmail('dev@starlinger.com');
        $client->setVatNumber('ATU12345678');
        $client->setIsActive(true);
        $client->setIsArchived(false);
        $client->setMaxActiveUsers(10);

        $this->entityManager->persist($client);
        $this->entityManager->flush();

        $io->success('Created test client: Starlinger Development (STL-DEV)');

        return $client;
    }

    private function loadTestUser(SymfonyStyle $io, Client $client): User
    {
        $io->section('Loading Test Users');

        // Define users for each role
        $usersData = [
            [
                'email' => 'super@starlinger.com',
                'firstName' => 'Super',
                'lastName' => 'Admin',
                'roles' => ['ROLE_ADMIN'],
                'phone' => '+43 1 234 0001',
                'needsClient' => false,
            ],
            [
                'email' => 'admin@starlinger.com',
                'firstName' => 'System',
                'lastName' => 'Admin',
                'roles' => ['ROLE_ADMIN'],
                'phone' => '+43 1 234 0002',
                'needsClient' => false,
            ],
            [
                'email' => 'clientadmin@starlinger.com',
                'firstName' => 'Client',
                'lastName' => 'Admin',
                'roles' => ['ROLE_CLIENT_ADMIN', 'ROLE_CLIENT'],
                'phone' => '+43 1 234 0003',
                'needsClient' => true,
            ],
            [
                'email' => 'recouser@starlinger.com',
                'firstName' => 'Reco',
                'lastName' => 'Developer',
                'roles' => ['ROLE_USER', 'ROLE_CLIENT'],
                'phone' => '+43 1 234 5678',
                'needsClient' => true,
            ],
        ];

        $password = 'recouser123!';
        $createdUsers = [];
        $primaryUser = null;

        foreach ($usersData as $data) {
            $existing = $this->entityManager->getRepository(User::class)->findOneBy(['email' => $data['email']]);
            if ($existing) {
                $createdUsers[] = $existing;
                if ($data['email'] === 'recouser@starlinger.com') {
                    $primaryUser = $existing;
                }
                continue;
            }

            $user = new User();
            $user->setEmail($data['email']);
            $user->setFirstName($data['firstName']);
            $user->setLastName($data['lastName']);
            $user->setPhoneNumber($data['phone']);
            $user->setRoles($data['roles']);
            $user->setIsActive(true);

            if ($data['needsClient']) {
                $user->setClient($client);
            }

            // Hash password (same for all test users)
            $hashedPassword = $this->passwordHasher->hashPassword($user, $password);
            $user->setPassword($hashedPassword);

            $this->entityManager->persist($user);
            $createdUsers[] = $user;

            if ($data['email'] === 'recouser@starlinger.com') {
                $primaryUser = $user;
            }
        }

        $this->entityManager->flush();

        $io->success(sprintf('Created/verified %d test users', count($createdUsers)));

        return $primaryUser ?? $createdUsers[0];
    }

    private function loadProducts(SymfonyStyle $io): void
    {
        $io->section('Loading Test Products');

        // Check if products already exist
        $existingCount = $this->entityManager->getRepository(Product::class)->count([]);
        if ($existingCount > 0) {
            $io->note(sprintf('Products already exist (%d found), skipping', $existingCount));
            return;
        }

        $partPrefixes = ['AIVV', 'BCSM', 'CTRL', 'DRVS', 'ELEC', 'FILT', 'GEAR', 'HYDR', 'INSP', 'JUNC'];
        $productNames = [
            'Drive Belt Assembly', 'Control Panel Module', 'Hydraulic Pump Unit',
            'Sensor Array Kit', 'Filter Cartridge Set', 'Bearing Housing',
            'Motor Controller Board', 'Pneumatic Valve', 'Gear Reducer Unit',
            'Heat Exchanger', 'Pressure Regulator', 'Temperature Sensor',
            'Flow Control Valve', 'Safety Switch', 'Power Supply Unit',
            'Conveyor Roller', 'Tension Spring Set', 'Coupling Assembly',
            'Seal Kit', 'Lubrication Pump', 'Limit Switch', 'Servo Motor',
            'Encoder Module', 'PLC Interface Card', 'Emergency Stop Button',
            'Cable Harness', 'Junction Box', 'Cooling Fan', 'Display Panel',
            'Keypad Assembly'
        ];

        $count = 0;
        for ($i = 0; $i < 30; $i++) {
            $prefix = $partPrefixes[$i % count($partPrefixes)];
            $partNumber = sprintf('%s-%05d', $prefix, 10000 + $i);

            $product = new Product();
            $product->setPartNo($partNumber);
            $product->setName(strtolower($partNumber));
            $product->setShortDescription($productNames[$i % count($productNames)]);
            $product->setUnit('piece');
            $product->setPrice(round(mt_rand(5000, 250000) / 100, 2));
            $product->setWeight(sprintf('%.2f kg', mt_rand(10, 5000) / 100));
            $product->setTechnicalDescription('High-quality replacement part for Starlinger machinery.');
            $product->setStatistic('ET');

            $this->entityManager->persist($product);
            $count++;

            if (($count % 10) === 0) {
                $this->entityManager->flush();
            }
        }

        $this->entityManager->flush();
        $io->success(sprintf('Loaded %d products', $count));
    }
}
