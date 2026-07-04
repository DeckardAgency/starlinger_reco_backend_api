<?php

namespace App\MessageHandler;

use App\Message\RefreshPendingTrackingMessage;
use App\Service\TrackingService;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
class RefreshPendingTrackingMessageHandler
{
    public function __construct(
        private readonly TrackingService $trackingService,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(RefreshPendingTrackingMessage $message): void
    {
        $stats = $this->trackingService->refreshAllPending();

        $this->logger->info('Scheduled tracking refresh completed', $stats);
    }
}
