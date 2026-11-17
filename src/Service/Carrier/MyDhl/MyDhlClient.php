<?php

namespace App\Service\Carrier\MyDhl;

use App\Enum\TrackingStatus;
use App\Service\Carrier\Dto\TrackingEventDto;
use App\Service\Carrier\MyDhl\Dto\PickupResult;
use App\Service\Carrier\MyDhl\Dto\RateQuote;
use App\Service\Carrier\MyDhl\Dto\ShipmentResult;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Client for the DHL Express MyDHL API (shipments, labels, pickups, rates, tracking).
 *
 * Docs: https://developer.dhl.com/api-reference/dhl-express-mydhl-api
 * Base URL: https://express.api.dhl.com/mydhlapi/ (prod) or .../mydhlapi/test/
 * Auth: HTTP Basic (API username/password); the shipper account number goes in
 * every request body/query — this client adds it.
 *
 * Unlike the tracking-only DhlClient, failures here throw MyDhlException: the
 * admin is waiting on the result and needs DHL's reason (e.g. pickup window errors).
 */
class MyDhlClient
{
    public function __construct(
        private readonly HttpClientInterface $httpClient,
        private readonly LoggerInterface $logger,
        private readonly string $apiUrl,
        private readonly string $username,
        private readonly string $password,
        private readonly string $accountNumber,
    ) {
    }

    public function isConfigured(): bool
    {
        return $this->apiUrl !== '' && $this->username !== '' && $this->password !== '' && $this->accountNumber !== '';
    }

    public function isTestEnvironment(): bool
    {
        return str_contains($this->apiUrl, '/test');
    }

    public function getAccountNumber(): string
    {
        return $this->accountNumber;
    }

    /**
     * GET /rates — products and prices for one route/weight/date.
     *
     * @param array<string, scalar> $query originCountryCode, originCityName, destinationCountryCode,
     *                                     destinationCityName, weight, length, width, height,
     *                                     plannedShippingDate (Y-m-d), isCustomsDeclarable, ... (accountNumber is added)
     * @return RateQuote[]
     */
    public function getRates(array $query): array
    {
        $query += ['accountNumber' => $this->accountNumber, 'unitOfMeasurement' => 'metric'];
        foreach ($query as $key => $value) {
            if (is_bool($value)) {
                $query[$key] = $value ? 'true' : 'false';
            }
        }

        $data = $this->request('GET', 'rates', ['query' => $query]);

        return array_map(RateQuote::fromArray(...), $data['products'] ?? []);
    }

    /**
     * POST /shipments — books the shipment and returns AWB + label.
     *
     * @param array $payload MyDHL shipment request body; `accounts` is filled in when absent
     */
    public function createShipment(array $payload): ShipmentResult
    {
        $payload['accounts'] ??= [['typeCode' => 'shipper', 'number' => $this->accountNumber]];

        $result = ShipmentResult::fromArray($this->request('POST', 'shipments', ['json' => $payload]));
        if ($result->awb === '') {
            throw new MyDhlException('DHL accepted the shipment but returned no tracking number.');
        }

        return $result;
    }

    /**
     * POST /pickups — books a courier pickup for already-created shipments.
     *
     * @param array $payload MyDHL pickup request body; `accounts` is filled in when absent
     */
    public function createPickup(array $payload): PickupResult
    {
        $payload['accounts'] ??= [['typeCode' => 'shipper', 'number' => $this->accountNumber]];

        return PickupResult::fromArray($this->request('POST', 'pickups', ['json' => $payload]));
    }

    /**
     * GET /tracking — checkpoints of an Express waybill.
     *
     * Returns an empty list when DHL has no data (a freshly booked, not yet
     * scanned shipment, or an unknown number) and on errors (logged): tracking
     * is polled in the background, so a failed poll just means "try again later".
     *
     * @return TrackingEventDto[] oldest first
     */
    public function fetchTrackingEvents(string $awb): array
    {
        // Express waybills are 10 digits (11 with a check digit). Anything else — e.g. a
        // 20-digit DHL Parcel number typed in by hand — MyDHL rejects, so don't ask.
        $length = strlen(trim($awb));
        if ($length < 10 || $length > 11) {
            $this->logger->info('Tracking number is not a DHL Express waybill; skipping MyDHL tracking', ['awb' => $awb]);
            return [];
        }

        try {
            $data = $this->request('GET', 'tracking', ['query' => [
                'shipmentTrackingNumber' => $awb,
                'trackingView' => 'all-checkpoints',
                'levelOfDetail' => 'all',
            ]]);
        } catch (MyDhlException $e) {
            if ($e->getHttpStatus() !== 404) {
                $this->logger->warning('MyDHL tracking failed', ['awb' => $awb, 'error' => $e->getMessage()]);
            }
            return [];
        }

        $events = [];
        foreach ($data['shipments'][0]['events'] ?? [] as $raw) {
            // date + time are local to the checkpoint; GMTOffset makes them absolute
            $timestamp = trim(($raw['date'] ?? '') . 'T' . ($raw['time'] ?? '00:00:00') . ($raw['GMTOffset'] ?? '+00:00'));
            try {
                $occurredAt = new \DateTimeImmutable($timestamp);
            } catch (\Exception) {
                continue;
            }

            $location = null;
            foreach ($raw['serviceArea'] ?? [] as $area) {
                $location = $area['description'] ?? $area['code'] ?? null;
                break;
            }

            $events[] = new TrackingEventDto(
                status: TrackingStatus::fromDhlExpressEvent((string) ($raw['typeCode'] ?? ''), $raw['description'] ?? null),
                occurredAt: $occurredAt,
                description: $raw['description'] ?? null,
                location: $location,
            );
        }

        usort($events, static fn (TrackingEventDto $a, TrackingEventDto $b) => $a->occurredAt <=> $b->occurredAt);

        return $events;
    }

    /**
     * DELETE /pickups/{dispatchConfirmationNumber}
     *
     * @param string $reason one of DHL's reason codes, e.g. "not needed", "other"
     */
    public function cancelPickup(string $dispatchConfirmationNumber, string $requestorName, string $reason = 'not needed'): void
    {
        $this->request('DELETE', 'pickups/' . rawurlencode($dispatchConfirmationNumber), [
            'query' => ['requestorName' => $requestorName, 'reason' => $reason],
        ]);
    }

    /**
     * @return array decoded JSON body (empty for bodiless success responses)
     * @throws MyDhlException
     */
    private function request(string $method, string $path, array $options): array
    {
        if (!$this->isConfigured()) {
            throw new MyDhlException('DHL Express is not configured (DHL_EXPRESS_* environment variables).');
        }

        // Echoed back by DHL and quoted in support tickets; 28-36 chars per spec.
        $messageReference = bin2hex(random_bytes(16));
        $url = rtrim($this->apiUrl, '/') . '/' . $path;

        $options += [
            'auth_basic' => [$this->username, $this->password],
            // timeout = idle time between chunks; max_duration caps the whole call.
            // Shipment creation (label rendering) is the slowest call, typically 3-8s.
            'timeout' => 15,
            'max_duration' => 30,
        ];
        $options['headers'] = ($options['headers'] ?? []) + [
            'Accept' => 'application/json',
            'Message-Reference' => $messageReference,
        ];

        try {
            $response = $this->httpClient->request($method, $url, $options);
            $status = $response->getStatusCode();
            $body = $response->getContent(false);
        } catch (ExceptionInterface $e) {
            $this->logger->error('MyDHL request failed', [
                'method' => $method, 'path' => $path, 'message_reference' => $messageReference, 'error' => $e->getMessage(),
            ]);
            throw new MyDhlException('Could not reach DHL: ' . $e->getMessage(), null, [], $messageReference, $e);
        }

        $data = $body !== '' ? json_decode($body, true) : [];
        if (!is_array($data)) {
            $data = [];
        }

        if ($status >= 300) {
            [$message, $details] = $this->extractError($data, $status);
            $this->logger->warning('MyDHL request rejected', [
                'method' => $method, 'path' => $path, 'status' => $status,
                'message_reference' => $messageReference, 'error' => $message,
            ]);
            throw new MyDhlException($message, $status, $details, $messageReference);
        }

        $this->logger->info('MyDHL request ok', [
            'method' => $method, 'path' => $path, 'status' => $status, 'message_reference' => $messageReference,
        ]);

        return $data;
    }

    /**
     * MyDHL has two error shapes:
     *  - gateway (auth):  {"reasons": [{"msg": "Invalid Credentials"}], ...}
     *  - API (validation): {"title": "...", "detail": "...", "additionalDetails": ["5002: ...", ...]}
     *
     * @return array{0: string, 1: string[]}
     */
    private function extractError(array $data, int $status): array
    {
        $details = array_values(array_map('strval', $data['additionalDetails'] ?? []));

        $message = $data['detail']
            ?? $data['message']
            ?? $data['reasons'][0]['msg']
            ?? $data['title']
            ?? sprintf('DHL returned HTTP %d', $status);

        if ($status === 401) {
            $message = 'DHL rejected the API credentials (' . $message . ').';
        }

        if ($details) {
            $message .= ' ' . implode(' ', $details);
        }

        return [trim((string) $message), $details];
    }
}
