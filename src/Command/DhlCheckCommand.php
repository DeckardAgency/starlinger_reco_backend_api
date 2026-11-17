<?php

namespace App\Command;

use App\Service\Carrier\MyDhl\MyDhlClient;
use App\Service\Carrier\MyDhl\MyDhlException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Verifies the DHL Express (MyDHL) configuration of this environment with a
 * read-only rates call — books nothing, costs nothing. Run after deploying env vars.
 */
#[AsCommand(
    name: 'app:dhl:check',
    description: 'Verify DHL Express credentials and account with a read-only rates call',
)]
class DhlCheckCommand extends Command
{
    public function __construct(private readonly MyDhlClient $client)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('to-country', null, InputOption::VALUE_REQUIRED, 'Destination country (ISO-2)', 'DE')
            ->addOption('to-city', null, InputOption::VALUE_REQUIRED, 'Destination city', 'Berlin')
            ->addOption('to-postal-code', null, InputOption::VALUE_REQUIRED, 'Destination postal code', '10115')
            ->addOption('weight', null, InputOption::VALUE_REQUIRED, 'Weight in kg', '2')
            ->addOption('dutiable', null, InputOption::VALUE_NONE, 'Price as a customs-declarable (non-EU) shipment');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('DHL Express (MyDHL) check');

        $io->definitionList(
            ['Environment' => $this->client->isTestEnvironment() ? 'TEST (nothing real is booked)' : 'PRODUCTION'],
            ['Account number' => $this->client->getAccountNumber() ?: '(missing)'],
        );

        if (!$this->client->isConfigured()) {
            $io->error('DHL_EXPRESS_API_URL, _USERNAME, _PASSWORD and _ACCOUNT_NUMBER must all be set.');
            return Command::FAILURE;
        }

        // Next weekday — DHL prices no pickups on weekends
        $date = new \DateTimeImmutable('tomorrow');
        while ((int) $date->format('N') >= 6) {
            $date = $date->modify('+1 day');
        }

        try {
            $quotes = $this->client->getRates([
                'originCountryCode' => 'AT',
                'originCityName' => 'Weissenbach',
                'originPostalCode' => '2564',
                'destinationCountryCode' => strtoupper((string) $input->getOption('to-country')),
                'destinationCityName' => (string) $input->getOption('to-city'),
                'destinationPostalCode' => (string) $input->getOption('to-postal-code'),
                'weight' => (float) $input->getOption('weight'),
                'length' => 20,
                'width' => 15,
                'height' => 10,
                'plannedShippingDate' => $date->format('Y-m-d'),
                'isCustomsDeclarable' => (bool) $input->getOption('dutiable'),
            ]);
        } catch (MyDhlException $e) {
            $io->error($e->getMessage());
            return Command::FAILURE;
        }

        $rows = [];
        foreach ($quotes as $quote) {
            $rows[] = [
                $quote->productCode,
                $quote->productName,
                $quote->price !== null ? sprintf('%.2f %s', $quote->price, $quote->currency) : '—',
                $quote->requiresAgreement ? 'yes' : '',
                $quote->estimatedDeliveryAt?->format('Y-m-d H:i') ?? '',
                $quote->pickupCutoffAt?->format('H:i') ?? '',
            ];
        }
        $io->table(['Code', 'Product', 'Price', 'Agreement only', 'Est. delivery', 'Pickup cutoff'], $rows);

        $io->success(sprintf('Credentials and account OK — %d product(s) for AT → %s on %s.',
            count($quotes), strtoupper((string) $input->getOption('to-country')), $date->format('Y-m-d')));

        return Command::SUCCESS;
    }
}
