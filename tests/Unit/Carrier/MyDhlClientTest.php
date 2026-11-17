<?php

declare(strict_types=1);

namespace App\Tests\Unit\Carrier;

use App\Service\Carrier\MyDhl\MyDhlClient;
use App\Service\Carrier\MyDhl\MyDhlException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * MyDhlClient against canned MyDHL responses (shapes taken from live test-API
 * calls, Oct 2026): request building (URL, auth, account), response parsing,
 * and both DHL error formats.
 */
final class MyDhlClientTest extends TestCase
{
    private const URL = 'https://express.api.dhl.com/mydhlapi/test/';

    /** @var array<int, array{method:string, url:string, options:array}> */
    private array $requests = [];

    public function testRatesAddAccountAndParseBillingPrice(): void
    {
        $client = $this->client(new MockResponse(json_encode(['products' => [
            [
                'productName' => 'EXPRESS WORLDWIDE', 'productCode' => 'U', 'isCustomerAgreement' => false,
                'totalPrice' => [
                    ['currencyType' => 'BILLC', 'priceCurrency' => 'EUR', 'price' => 47.08],
                    ['currencyType' => 'PULCL', 'price' => 0],
                ],
                'pickupCapabilities' => ['localCutoffDateAndTime' => '2026-10-19T13:00:00'],
                'deliveryCapabilities' => ['estimatedDeliveryDateAndTime' => '2026-10-20T23:59:00'],
            ],
            [
                'productName' => 'PARCEL PRODUCT', 'productCode' => '9', 'isCustomerAgreement' => true,
                'totalPrice' => [['currencyType' => 'BILLC', 'priceCurrency' => 'EUR', 'price' => 0]],
            ],
        ]])));

        $quotes = $client->getRates(['originCountryCode' => 'AT', 'isCustomsDeclarable' => false]);

        $request = $this->requests[0];
        self::assertSame('GET', $request['method']);
        self::assertStringStartsWith(self::URL . 'rates?', $request['url']);
        self::assertStringContainsString('accountNumber=170600864', $request['url']);
        self::assertStringContainsString('isCustomsDeclarable=false', $request['url']);
        self::assertStringContainsString('unitOfMeasurement=metric', $request['url']);

        self::assertCount(2, $quotes);
        self::assertSame('U', $quotes[0]->productCode);
        self::assertSame(47.08, $quotes[0]->price);
        self::assertSame('EUR', $quotes[0]->currency);
        self::assertSame('2026-10-19 13:00', $quotes[0]->pickupCutoffAt?->format('Y-m-d H:i'));
        self::assertFalse($quotes[0]->requiresAgreement);
        // DHL's 0 price means "not priced for this account", not "free"
        self::assertNull($quotes[1]->price);
        self::assertTrue($quotes[1]->requiresAgreement);
    }

    public function testRequestsUseBasicAuthAndMessageReference(): void
    {
        $client = $this->client(new MockResponse('{"products":[]}'));
        $client->getRates([]);

        $headers = implode("\n", $this->requests[0]['options']['headers']);
        self::assertStringContainsString('Authorization: Basic ' . base64_encode('apiuser:pa$s#word'), $headers);
        self::assertMatchesRegularExpression('/Message-Reference: [0-9a-f]{32}/', $headers);
    }

    public function testBaseUrlWithoutTrailingSlashStillHitsTheTestApi(): void
    {
        $client = $this->client(new MockResponse('{"products":[]}'), rtrim(self::URL, '/'));
        $client->getRates([]);

        self::assertStringStartsWith(self::URL . 'rates?', $this->requests[0]['url']);
    }

    public function testCreateShipmentAddsAccountAndDecodesLabel(): void
    {
        $pdf = "%PDF-1.4 fake label";
        $client = $this->client(new MockResponse(json_encode([
            'shipmentTrackingNumber' => '1234567890',
            'packages' => [['referenceNumber' => 1, 'trackingNumber' => 'JD014600006281230704']],
            'documents' => [['imageFormat' => 'PDF', 'content' => base64_encode($pdf), 'typeCode' => 'label']],
            'shipmentCharges' => [['currencyType' => 'BILLC', 'priceCurrency' => 'EUR', 'price' => 47.08]],
            'estimatedDeliveryDate' => ['estimatedDeliveryDate' => '2026-10-20', 'estimatedDeliveryType' => 'QDDC'],
            'warnings' => ['Pickup not requested'],
        ]), ['http_code' => 201]));

        $result = $client->createShipment(['productCode' => 'U']);

        self::assertSame('POST', $this->requests[0]['method']);
        self::assertSame(self::URL . 'shipments', $this->requests[0]['url']);
        $sent = json_decode($this->requests[0]['options']['body'], true);
        self::assertSame([['typeCode' => 'shipper', 'number' => '170600864']], $sent['accounts']);

        self::assertSame('1234567890', $result->awb);
        self::assertSame(['JD014600006281230704'], $result->pieceTrackingNumbers);
        self::assertSame($pdf, $result->getLabel());
        self::assertSame(47.08, $result->price);
        self::assertSame('2026-10-20', $result->estimatedDeliveryAt?->format('Y-m-d'));
        self::assertSame(['Pickup not requested'], $result->warnings);
    }

    public function testValidationErrorCarriesDhlDetails(): void
    {
        // Same shape as the legacy system's failed pickup (dhl_parcel_entity #57)
        $client = $this->client(new MockResponse(json_encode([
            'instance' => '/expressapi/shipments',
            'detail' => 'Multiple problems found, see Additional Details',
            'title' => 'Unprocessable Entity',
            'status' => '422',
            'additionalDetails' => [
                '5002: The Pickup time window for a collection to take place is too small.',
                '5005: The minimum pickup time window overlap for your requested origin is 90 minutes.',
            ],
        ]), ['http_code' => 422]));

        try {
            $client->createShipment([]);
            self::fail('Expected MyDhlException');
        } catch (MyDhlException $e) {
            self::assertSame(422, $e->getHttpStatus());
            self::assertCount(2, $e->getDetails());
            self::assertStringContainsString('5002: The Pickup time window', $e->getMessage());
            self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/', (string) $e->getMessageReference());
        }
    }

    public function testInvalidCredentialsAreReportedAsSuch(): void
    {
        $client = $this->client(new MockResponse(
            '{"reasons": [{"msg": "Invalid Credentials"}], "details": {"msgId": "x"}}',
            ['http_code' => 401]
        ));

        $this->expectException(MyDhlException::class);
        $this->expectExceptionMessage('DHL rejected the API credentials (Invalid Credentials).');
        $client->getRates([]);
    }

    public function testCancelPickupSendsRequestorAndReason(): void
    {
        $client = $this->client(new MockResponse('', ['http_code' => 200]));
        $client->cancelPickup('PRG201220123456', 'Jane Admin');

        self::assertSame('DELETE', $this->requests[0]['method']);
        self::assertStringStartsWith(self::URL . 'pickups/PRG201220123456?', $this->requests[0]['url']);
        self::assertStringContainsString('requestorName=Jane%20Admin', $this->requests[0]['url']);
    }

    public function testTrackingEventsAreMappedAndSortedOldestFirst(): void
    {
        $client = $this->client(new MockResponse(json_encode(['shipments' => [[
            'shipmentTrackingNumber' => '8501813320', 'status' => 'Success',
            'events' => [
                ['date' => '2026-10-20', 'time' => '09:41:00', 'GMTOffset' => '+01:00', 'typeCode' => 'OK',
                 'description' => 'Delivered - Signed for by: J.DOE', 'serviceArea' => [['code' => 'BER', 'description' => 'Berlin - Germany']], 'signedBy' => 'J.DOE'],
                ['date' => '2026-10-19', 'time' => '15:18:00', 'GMTOffset' => '+02:00', 'typeCode' => 'PU',
                 'description' => 'Shipment picked up', 'serviceArea' => [['code' => 'VIE', 'description' => 'Vienna - Austria']]],
                ['date' => '2026-10-20', 'time' => '08:02:00', 'GMTOffset' => '+01:00', 'typeCode' => 'WC',
                 'description' => 'Shipment is out with courier for delivery', 'serviceArea' => [['code' => 'BER', 'description' => 'Berlin - Germany']]],
            ],
        ]]])));

        $events = $client->fetchTrackingEvents('8501813320');

        self::assertStringStartsWith(self::URL . 'tracking?', $this->requests[0]['url']);
        self::assertStringContainsString('shipmentTrackingNumber=8501813320', $this->requests[0]['url']);
        self::assertSame(['picked_up', 'out_for_delivery', 'delivered'], array_map(static fn ($e) => $e->status->value, $events));
        self::assertSame('2026-10-19T13:18:00+00:00', $events[0]->occurredAt->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM));
        self::assertSame('Vienna - Austria', $events[0]->location);
        self::assertSame('Delivered - Signed for by: J.DOE', $events[2]->description);
    }

    public function testTrackingOfUnknownNumberIsEmptyNotAnError(): void
    {
        $client = $this->client(new MockResponse(
            '{"detail":"No Shipments Found for shipmentTrackingNumber 1234567890","title":"No data found","status":"404"}',
            ['http_code' => 404]
        ));

        self::assertSame([], $client->fetchTrackingEvents('1234567890'));
    }

    public function testUnconfiguredClientFailsWithoutCallingDhl(): void
    {
        $client = new MyDhlClient(new MockHttpClient(function () {
            self::fail('No request expected');
        }), new NullLogger(), self::URL, '', '', '170600864');

        self::assertFalse($client->isConfigured());
        $this->expectException(MyDhlException::class);
        $client->getRates([]);
    }

    private function client(MockResponse $response, string $url = self::URL): MyDhlClient
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use ($response) {
            $this->requests[] = ['method' => $method, 'url' => $url, 'options' => $options];
            return $response;
        });

        return new MyDhlClient($http, new NullLogger(), $url, 'apiuser', 'pa$s#word', '170600864');
    }
}
