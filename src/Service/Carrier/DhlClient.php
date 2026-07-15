<?php

namespace App\Service\Carrier;

use App\Enum\TrackingStatus;
use App\Service\Carrier\Dto\TrackingEventDto;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client for DHL's public Track and Trace API.
 *
 * Docs: https://developer.dhl.com/api-reference/shipment-tracking
 * Endpoint: GET {baseUrl}/track/shipments?trackingNumber=XYZ
 * Auth: header DHL-API-Key
 */
class DhlClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiKey,
        private readonly string $baseUrl,
    ) {
    }

    /**
     * Fetch tracking events from DHL for a tracking number.
     *
     * @return TrackingEventDto[] events ordered chronologically (oldest first)
     */
    public function fetchTrackingEvents(string $trackingNumber): array
    {
        if (empty($this->apiKey)) {
            $this->logger->warning('DHL API key is not configured; skipping tracking refresh.');
            return [];
        }

        try {
            $response = $this->httpClient->request('GET', rtrim($this->baseUrl, '/') . '/track/shipments', [
                'query' => ['trackingNumber' => $trackingNumber],
                'headers' => [
                    'DHL-API-Key' => $this->apiKey,
                    'Accept' => 'application/json',
                ],
                // timeout = idle timeout between chunks; max_duration caps the total
                // wall time so a hung DHL endpoint can't tie up a PHP-FPM worker
                // (this call runs synchronously on the manual-refresh endpoint).
                'timeout' => 8,
                'max_duration' => 12,
            ]);

            $data = $response->toArray(false);
        } catch (ClientException | ServerException | TransportException $e) {
            $this->logger->error('DHL tracking request failed', [
                'tracking_number' => $trackingNumber,
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        $shipment = $data['shipments'][0] ?? null;
        if (!$shipment) {
            return [];
        }

        $events = [];
        foreach ($shipment['events'] ?? [] as $raw) {
            $statusCode = $raw['statusCode'] ?? $raw['status'] ?? '';
            $occurredAt = !empty($raw['timestamp'])
                ? new \DateTimeImmutable($raw['timestamp'])
                : new \DateTimeImmutable();

            $events[] = new TrackingEventDto(
                status: TrackingStatus::fromDhlStatus($statusCode),
                occurredAt: $occurredAt,
                description: $raw['description'] ?? $raw['status'] ?? null,
                location: $raw['location']['address']['addressLocality'] ?? null,
            );
        }

        // DHL returns newest-first; reverse so callers persist chronologically
        return array_reverse($events);
    }
}
