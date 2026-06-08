<?php

namespace App\Command;

use App\Service\TrackingService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'app:tracking:refresh-pending',
    description: 'Refresh tracking status from carrier APIs for all orders with active tracking',
)]
class RefreshPendingTrackingCommand extends Command
{
    public function __construct(private readonly TrackingService $trackingService)
    {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $io->title('Tracking refresh — pending orders');

        $stats = $this->trackingService->refreshAllPending();

        $io->table(
            ['Metric', 'Count'],
            [
                ['Orders processed', $stats['processed']],
                ['New events recorded', $stats['created']],
                ['Errors', $stats['errors']],
            ]
        );

        if ($stats['errors'] > 0) {
            $io->warning(sprintf('%d order(s) failed to refresh — see logs.', $stats['errors']));
            return Command::FAILURE;
        }

        $io->success('Tracking refresh complete.');
        return Command::SUCCESS;
    }
}
