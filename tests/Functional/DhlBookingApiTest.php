<?php

declare(strict_types=1);

namespace App\Tests\Functional;

use App\Entity\Address;
use App\Entity\Client;
use App\Entity\Country;
use App\Entity\DeliveryType;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\Product;
use App\Entity\TaxType;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Service\Carrier\MyDhl\MyDhlClient;
use Doctrine\ORM\EntityManagerInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\JWTTokenManagerInterface;
use Psr\Log\NullLogger;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Admin DHL Express booking flow end to end, with MyDHL replaced by a recording
 * mock (response shapes from the live test API, Oct 2026):
 * prefill → book (EU / customs) → pickup → cancel, plus the guards and the
 * customer tracking-field write protection.
 *
 * Builds its own fixtures with a unique per-run suffix and cleans them up.
 */
final class DhlBookingApiTest extends WebTestCase
{
    private KernelBrowser $client;
    private EntityManagerInterface $em;
    private string $suffix;

    /** @var array<int, array{method:string, path:string, body:?array, url:string}> */
    private array $dhlRequests = [];
    /** @var array<string, MockResponse[]> "METHOD path" => queued responses */
    private array $dhlResponses = [];
    /** @var string[] AWBs whose stored label files must be removed */
    private array $awbs = [];
    /** @var Country[] countries created by this test (removed in tearDown) */
    private array $createdCountries = [];
    private ?TaxType $taxType = null;

    private User $admin;
    private User $customer;
    private Client $company;
    private Product $product;
    private DeliveryType $deliveryType;
    private Country $at;
    private Country $de;
    private Country $ch;

    protected function setUp(): void
    {
        self::ensureKernelShutdown();
        $this->client = static::createClient();
        $this->client->disableReboot();
        $this->em = self::getContainer()->get(EntityManagerInterface::class);
        $this->suffix = bin2hex(random_bytes(4));

        self::getContainer()->set(MyDhlClient::class, new MyDhlClient(
            new MockHttpClient(fn (string $method, string $url, array $options) => $this->respond($method, $url, $options)),
            new NullLogger(),
            'https://express.api.dhl.com/mydhlapi/test/',
            'apiuser',
            'secret',
            '170600864',
        ));

        $at = $this->at = $this->country('AT', 'Austria', true);
        $this->de = $this->country('DE', 'Germany', true);
        $this->ch = $this->country('CH', 'Switzerland', false);

        $warehouse = (new Warehouse())
            ->setName('Spare parts ' . $this->suffix)
            ->setAddress('Hauptstrasse 43')
            ->setPostalCode('2564')
            ->setCity('Weissenbach')
            ->setCountry($at)
            ->setEmail('service@example.com')
            ->setPhone('+43 2674 800')
            ->setContactPerson('Shipping desk');
        $this->em->persist($warehouse);

        $this->deliveryType = (new DeliveryType())
            ->setName('DHL ' . $this->suffix)
            ->setCarrierCode('DHL')
            ->setWarehouse($warehouse);
        $this->em->persist($this->deliveryType);

        $this->company = (new Client())->setName('Receiver GmbH ' . $this->suffix)->setCode('DB-' . $this->suffix);
        $this->company->setVatNumber('DE123456789');
        $this->em->persist($this->company);

        $this->admin = $this->user('admin', ['ROLE_ADMIN'], null);
        $this->customer = $this->user('customer', ['ROLE_CLIENT'], $this->company);
        $this->customer->setPhoneNumber('+49 30 1234');

        $this->product = (new Product())
            ->setName('Cutting knife ' . $this->suffix)
            ->setSlug('cutting-knife-' . $this->suffix)
            ->setPartNo('PN-' . $this->suffix)
            ->setPrice(60.0)
            ->setWeight('0.5 kg');
        $this->em->persist($this->product);

        $this->em->flush();
    }

    protected function tearDown(): void
    {
        try {
            foreach ($this->awbs as $awb) {
                foreach (glob(self::getContainer()->getParameter('kernel.project_dir') . '/var/storage/dhl-labels/' . $awb . '-*.pdf') ?: [] as $file) {
                    unlink($file);
                }
            }
            if (isset($this->em) && $this->em->isOpen()) {
                $conn = $this->em->getConnection();
                $orderIds = 'SELECT o.id FROM `order` o JOIN user u ON o.user_id = u.id WHERE u.email LIKE :s';
                $like = ['s' => '%-' . $this->suffix];
                $conn->executeStatement("DELETE FROM order_log WHERE order_id IN ($orderIds)", $like);
                $conn->executeStatement("DELETE FROM order_item WHERE order_ref_id IN ($orderIds)", $like);
                $conn->executeStatement("DELETE o FROM `order` o JOIN user u ON o.user_id = u.id WHERE u.email LIKE :s", $like);
                $conn->executeStatement('DELETE FROM address WHERE client_id = :c', ['c' => $this->company->getId()]);
                $conn->executeStatement('DELETE FROM user WHERE email LIKE :s', $like);
                $conn->executeStatement('DELETE FROM product WHERE slug LIKE :s', $like);
                $conn->executeStatement('DELETE FROM delivery_type WHERE id = :id', ['id' => $this->deliveryType->getId()]);
                $conn->executeStatement('DELETE FROM warehouse WHERE name = :n', ['n' => 'Spare parts ' . $this->suffix]);
                $conn->executeStatement('DELETE FROM client WHERE code = :c', ['c' => 'DB-' . $this->suffix]);
                foreach ($this->createdCountries as $country) {
                    $conn->executeStatement('DELETE FROM country WHERE id = :id', ['id' => $country->getId()]);
                }
                if ($this->taxType?->getId()) {
                    $conn->executeStatement('DELETE FROM tax_type WHERE id = :id', ['id' => $this->taxType->getId()]);
                }
            }
        } finally {
            parent::tearDown();
        }
    }

    public function testPrefillForEuOrder(): void
    {
        $order = $this->order($this->de);
        $this->queue('GET rates', ['products' => [[
            'productCode' => 'U', 'productName' => 'EXPRESS WORLDWIDE', 'isCustomerAgreement' => false,
            'totalPrice' => [['currencyType' => 'BILLC', 'priceCurrency' => 'EUR', 'price' => 47.08]],
        ]]]);

        $body = $this->call('GET', "/api/v1/orders/{$order->getId()}/dhl-shipment/prefill");

        self::assertSame(200, $this->httpStatus(), json_encode($body));
        self::assertFalse($body['isCustomsDeclarable']);
        self::assertSame('U', $body['productCode']);
        self::assertSame('2564', $body['shipper']['postalCode']);
        self::assertSame('AT', $body['shipper']['countryCode']);
        self::assertSame('Receiver GmbH ' . $this->suffix, $body['receiver']['companyName']);
        self::assertSame('10115', $body['receiver']['postalCode']);
        self::assertSame('+49 30 1234', $body['receiver']['phone']);
        self::assertSame(1.0, (float) $body['packages'][0]['weight']); // 2 × 0.5 kg
        self::assertSame(47.08, $body['rates'][0]['price']);
        self::assertSame([], $body['issues']);
        self::assertStringContainsString('destinationPostalCode=10115', $this->dhlRequests[0]['url']);
    }

    public function testDomesticOrdersUseTheDomesticExpressProduct(): void
    {
        $order = $this->order($this->at);
        // What the account really offers for AT → AT (app:dhl:check, Nov 2026)
        $this->queue('GET rates', ['products' => [
            ['productCode' => '1', 'productName' => 'EXPRESS DOMESTIC 12:00', 'isCustomerAgreement' => false, 'totalPrice' => [['currencyType' => 'BILLC', 'priceCurrency' => 'EUR', 'price' => 27.82]]],
            ['productCode' => 'N', 'productName' => 'EXPRESS DOMESTIC', 'isCustomerAgreement' => false, 'totalPrice' => [['currencyType' => 'BILLC', 'priceCurrency' => 'EUR', 'price' => 19.42]]],
        ]]);

        $body = $this->call('GET', "/api/v1/orders/{$order->getId()}/dhl-shipment/prefill");

        self::assertSame(200, $this->httpStatus(), json_encode($body));
        self::assertSame('N', $body['productCode'], '"U" (Express Worldwide) does not exist for domestic lanes');
        self::assertFalse($body['isCustomsDeclarable']);

        $this->queueShipment(withInvoice: false);
        $this->call('POST', "/api/v1/orders/{$order->getId()}/dhl-shipments", array_replace($body, ['packages' => [['weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10]]]));
        self::assertSame(201, $this->httpStatus());
        self::assertSame('N', $this->lastRequest('POST shipments')['body']['productCode']);
    }

    public function testPrefillFallsBackToAProductDhlActuallyQuotes(): void
    {
        $order = $this->order($this->de);
        // Lane where the configured product is not offered: only Economy Select and an agreement-only product
        $this->queue('GET rates', ['products' => [
            ['productCode' => 'C', 'productName' => 'MEDICAL EXPRESS', 'isCustomerAgreement' => true, 'totalPrice' => [['currencyType' => 'BILLC', 'priceCurrency' => 'EUR', 'price' => 400.0]]],
            ['productCode' => 'W', 'productName' => 'ECONOMY SELECT', 'isCustomerAgreement' => false, 'totalPrice' => [['currencyType' => 'BILLC', 'priceCurrency' => 'EUR', 'price' => 19.73]]],
        ]]);

        $body = $this->call('GET', "/api/v1/orders/{$order->getId()}/dhl-shipment/prefill");

        self::assertSame('W', $body['productCode'], 'Cheapest quoted product that needs no agreement');
    }

    public function testPrefillForNonEuOrderFlagsMissingCustomsData(): void
    {
        $order = $this->order($this->ch);
        $this->queue('GET rates', ['products' => []]);

        $body = $this->call('GET', "/api/v1/orders/{$order->getId()}/dhl-shipment/prefill");

        self::assertTrue($body['isCustomsDeclarable']);
        self::assertSame('P', $body['productCode'], 'Express Worldwide maps to its dutiable code');
        self::assertSame('', $body['lineItems'][0]['hsCode']);
        self::assertSame(1.0, (float) $body['lineItems'][0]['netWeight']);
        self::assertStringContainsString('HS code', implode(' ', $body['issues']));
        self::assertStringContainsString('country of origin', implode(' ', $body['issues']));
    }

    public function testBookEuShipmentMovesOrderToReadyForShipment(): void
    {
        $order = $this->order($this->de, Order::STATUS_IN_PROCESS);
        $awb = $this->queueShipment(withInvoice: false);
        $input = $this->bookingInput($order);
        $input['reference'] = 'EA2068302 ZGL EA4109536';
        $input['plannedShippingTime'] = '14:30';

        $body = $this->call('POST', "/api/v1/orders/{$order->getId()}/dhl-shipments", $input);

        self::assertSame(201, $this->httpStatus(), json_encode($body));
        self::assertSame($awb, $body['shipment']['awb']);
        self::assertSame(['label'], $body['shipment']['documentTypes']);
        self::assertSame('EA2068302 ZGL EA4109536', $body['shipment']['reference']);
        self::assertStringContainsString('T14:30:00', $body['shipment']['plannedShippingAt']);
        self::assertSame('ready_for_shipment', $body['order']['status'], 'Label created = ready for the warehouse; shipped follows the pickup scan');
        self::assertSame($awb, $body['order']['trackingNumber']);
        self::assertSame('dhl', $body['order']['trackingCarrier']);
        self::assertStringContainsString($awb, (string) $body['order']['trackingUrl']);

        $sent = $this->lastRequest('POST shipments')['body'];
        self::assertSame('U', $sent['productCode']);
        self::assertFalse($sent['content']['isCustomsDeclarable']);
        self::assertArrayNotHasKey('exportDeclaration', $sent['content']);
        self::assertSame('ECOM26_A4_001', $sent['outputImageProperties']['imageOptions'][0]['templateName']);
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}T14:30:00 GMT\+0[12]:00$/', $sent['plannedShippingDateAndTime']);
        self::assertSame([
            ['value' => 'EA2068302 ZGL EA4109536', 'typeCode' => 'CU'],
            ['value' => $order->getOrderNumber(), 'typeCode' => 'CU'],
        ], $sent['customerReferences'], 'The warehouse reference comes first on the label');
        self::assertSame('170600864', $sent['accounts'][0]['number']);
        self::assertSame('DE123456789', $sent['customerDetails']['receiverDetails']['registrationNumbers'][0]['number']);
        self::assertSame(['weight' => 1.2, 'dimensions' => ['length' => 30.0, 'width' => 20.0, 'height' => 15.0]], $sent['content']['packages'][0]);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Order::class)->find($order->getId());
        self::assertSame('ready_for_shipment', $reloaded->getStatus());
        self::assertNull($reloaded->getDispatchedAt(), 'Not dispatched until DHL picks the parcel up');

        // Label is stored and downloadable
        $this->call('GET', "/api/v1/dhl_shipments/{$body['shipment']['id']}/documents/label");
        self::assertSame(200, $this->httpStatus());
    }

    public function testBookCustomsShipmentRequiresHsCodesThenRemembersThem(): void
    {
        $order = $this->order($this->ch);
        $input = $this->bookingInput($order);

        $body = $this->call('POST', "/api/v1/orders/{$order->getId()}/dhl-shipments", $input);
        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('HS code', implode(' ', $body['errors']));
        self::assertSame([], $this->dhlRequests, 'Nothing is sent to DHL when customs data is missing');

        $input['lineItems'][0]['hsCode'] = '8208.90.00';
        $input['lineItems'][0]['countryOfOrigin'] = 'at';
        $input['labelFormat'] = 'thermal';
        $awb = $this->queueShipment(withInvoice: true);

        $body = $this->call('POST', "/api/v1/orders/{$order->getId()}/dhl-shipments", $input);

        self::assertSame(201, $this->httpStatus(), json_encode($body));
        self::assertSame(['label', 'invoice'], $body['shipment']['documentTypes']);
        self::assertTrue($body['shipment']['isCustomsDeclarable']);

        $sent = $this->lastRequest('POST shipments')['body'];
        self::assertSame('P', $sent['productCode'], 'EU product code is mapped to its dutiable code');
        self::assertTrue($sent['content']['isCustomsDeclarable']);
        self::assertSame('ECOM26_84_001', $sent['outputImageProperties']['imageOptions'][0]['templateName']);
        self::assertSame('invoice', $sent['outputImageProperties']['imageOptions'][1]['typeCode']);
        self::assertSame(120.0, (float) $sent['content']['declaredValue']);
        $line = $sent['content']['exportDeclaration']['lineItems'][0];
        self::assertSame('82089000', $line['commodityCodes'][0]['value']);
        self::assertSame('AT', $line['manufacturerCountry']);
        self::assertSame(2, $line['quantity']['value']);
        self::assertSame(60.0, (float) $line['price']);
        self::assertSame($order->getOrderNumber(), $sent['content']['exportDeclaration']['invoice']['number']);

        $this->em->clear();
        $product = $this->em->getRepository(Product::class)->find($this->product->getId());
        self::assertSame('82089000', $product->getHsCode(), 'HS code is remembered on the product');
        self::assertSame('AT', $product->getCountryOfOrigin());
        self::assertNotEmpty($awb);
    }

    public function testDhlRejectionLeavesOrderUntouched(): void
    {
        $order = $this->order($this->de);
        $this->queue('POST shipments', [
            'title' => 'Unprocessable Entity', 'status' => '422',
            'detail' => 'Multiple problems found, see Additional Details',
            'additionalDetails' => ['420505: The postal code is invalid for the destination.'],
        ], 422);

        $body = $this->call('POST', "/api/v1/orders/{$order->getId()}/dhl-shipments", $this->bookingInput($order));

        self::assertSame(422, $this->httpStatus());
        self::assertStringContainsString('420505: The postal code is invalid', $body['message']);
        self::assertNotEmpty($body['dhlReference']);

        $this->em->clear();
        $reloaded = $this->em->getRepository(Order::class)->find($order->getId());
        self::assertSame('ready_for_shipment', $reloaded->getStatus());
        self::assertNull($reloaded->getTrackingNumber());

        // The refused attempt is kept with DHL's reason, and does not block a new request
        $list = $this->call('GET', "/api/v1/dhl_shipments?order.id={$order->getId()}");
        self::assertCount(1, $list['member']);
        self::assertSame('failed', $list['member'][0]['status']);
        self::assertNull($list['member'][0]['awb']);
        self::assertStringContainsString('420505', $list['member'][0]['errorMessage']);
        self::assertSame($order->getOrderNumber(), $list['member'][0]['orderNumber']);
        self::assertSame('Receiver GmbH ' . $this->suffix, $list['member'][0]['customerName']);

        $this->queueShipment(withInvoice: false);
        $this->call('POST', "/api/v1/orders/{$order->getId()}/dhl-shipments", $this->bookingInput($order));
        self::assertSame(201, $this->httpStatus());
    }

    public function testFailedPickupKeepsTheShipmentAndCanBeRetried(): void
    {
        $order = $this->order($this->de);
        $input = $this->bookingInput($order);
        $input['pickup'] = ['requested' => true, 'readyTime' => '13:00', 'closeTime' => '16:00', 'location' => 'Gate 2'];
        $this->queueShipment(withInvoice: false);
        $this->queue('POST pickups', [
            'title' => 'Unprocessable Entity', 'status' => '422', 'detail' => 'Multiple problems found, see Additional Details',
            'additionalDetails' => ['5002: The Pickup time window for a collection to take place is too small.'],
        ], 422);

        $body = $this->call('POST', "/api/v1/orders/{$order->getId()}/dhl-shipments", $input);

        self::assertSame(201, $this->httpStatus(), json_encode($body));
        self::assertSame('ready_for_shipment', $body['order']['status']);
        self::assertStringContainsString('5002', (string) $body['shipment']['pickupError']);
        self::assertNull($body['shipment']['pickupConfirmationNumber']);
        $pickupSent = $this->lastRequest('POST pickups')['body'];
        self::assertSame('16:00', $pickupSent['closeTime']);
        self::assertSame('Gate 2', $pickupSent['location']);

        // Retry with a later window
        $this->queue('POST pickups', ['dispatchConfirmationNumbers' => ['PRG261019000671']], 201);
        $body = $this->call('POST', "/api/v1/dhl_shipments/{$body['shipment']['id']}/pickup", ['readyTime' => '13:30', 'closeTime' => '17:00']);

        self::assertSame(200, $this->httpStatus(), json_encode($body));
        self::assertSame('PRG261019000671', $body['shipment']['pickupConfirmationNumber']);
        self::assertNull($body['shipment']['pickupError']);
        self::assertSame('17:00', $body['shipment']['pickupCloseTime']);
    }

    public function testCancelReturnsOrderToReadyForShipment(): void
    {
        $order = $this->order($this->de);
        $input = $this->bookingInput($order);
        $input['pickup'] = ['requested' => true];
        $this->queueShipment(withInvoice: false);
        $this->queue('POST pickups', ['dispatchConfirmationNumbers' => ['PRG261019000999']], 201);

        $booked = $this->call('POST', "/api/v1/orders/{$order->getId()}/dhl-shipments", $input);
        self::assertSame('PRG261019000999', $booked['shipment']['pickupConfirmationNumber']);

        $this->queue('DELETE pickups/PRG261019000999', ['message' => 'Successfully cancelled']);
        $body = $this->call('POST', "/api/v1/dhl_shipments/{$booked['shipment']['id']}/cancel");

        self::assertSame(200, $this->httpStatus(), json_encode($body));
        self::assertSame('cancelled', $body['shipment']['status']);
        self::assertNotNull($body['shipment']['pickupCancelledAt']);
        self::assertSame('ready_for_shipment', $body['order']['status']);
        self::assertNull($body['order']['trackingNumber']);
        self::assertStringContainsString('requestorName=', $this->lastRequest('DELETE pickups/PRG261019000999')['url']);

        // Re-booking is possible after a cancel
        $this->queueShipment(withInvoice: false);
        $this->call('POST', "/api/v1/orders/{$order->getId()}/dhl-shipments", $this->bookingInput($order));
        self::assertSame(201, $this->httpStatus());
    }

    public function testTrackingRefreshAfterBookingAdvancesTheOrder(): void
    {
        $order = $this->order($this->de);
        $awb = $this->queueShipment(withInvoice: false);
        $booked = $this->call('POST', "/api/v1/orders/{$order->getId()}/dhl-shipments", $this->bookingInput($order));
        self::assertSame(201, $this->httpStatus(), json_encode($booked));

        // Booking leaves a first "label created" entry on the timeline
        $this->em->clear();
        $events = $this->em->getRepository(Order::class)->find($order->getId())->getTrackingEvents();
        self::assertCount(1, $events);
        self::assertSame('created', $events->first()->getStatus());
        self::assertSame('dhl_api', $events->first()->getSource());

        // Scans must be newer than the "label created" event written at booking (now)
        $pickupDay = (new \DateTimeImmutable('+1 day'))->format('Y-m-d');
        $deliveryDay = (new \DateTimeImmutable('+2 days'))->format('Y-m-d');
        $this->queue('GET tracking', ['shipments' => [[
            'shipmentTrackingNumber' => $awb, 'status' => 'Success',
            'events' => [
                ['date' => $pickupDay, 'time' => '15:18:00', 'GMTOffset' => '+02:00', 'typeCode' => 'PU', 'description' => 'Shipment picked up', 'serviceArea' => [['code' => 'VIE', 'description' => 'Vienna - Austria']]],
                ['date' => $deliveryDay, 'time' => '08:02:00', 'GMTOffset' => '+01:00', 'typeCode' => 'WC', 'description' => 'With delivery courier', 'serviceArea' => [['code' => 'BER', 'description' => 'Berlin - Germany']]],
                ['date' => $deliveryDay, 'time' => '09:41:00', 'GMTOffset' => '+01:00', 'typeCode' => 'OK', 'description' => 'Delivered', 'serviceArea' => [['code' => 'BER', 'description' => 'Berlin - Germany']]],
            ],
        ]]]);

        $body = $this->call('POST', "/api/v1/orders/{$order->getId()}/tracking/refresh");

        self::assertSame(200, $this->httpStatus(), json_encode($body));
        self::assertSame(3, $body['created']);
        self::assertSame('delivered', $body['orderStatus']);
        $this->em->clear();
        self::assertNotNull($this->em->getRepository(Order::class)->find($order->getId())->getDispatchedAt(), 'The pickup scan stamps the dispatch date');
        self::assertStringContainsString('shipmentTrackingNumber=' . $awb, $this->lastRequest('GET tracking')['url']);
        $statuses = array_map(static fn (array $e) => $e['status'], $body['events']);
        self::assertSame(['delivered', 'out_for_delivery', 'picked_up', 'created'], $statuses, 'newest first');

        // Delivered shipments can no longer be cancelled from RECO
        $this->call('POST', "/api/v1/dhl_shipments/{$booked['shipment']['id']}/cancel");
        self::assertSame(409, $this->httpStatus());
    }

    public function testGuards(): void
    {
        foreach ([Order::STATUS_NEW, Order::STATUS_WAITING_FOR_PAYMENT] as $tooEarly) {
            $order = $this->order($this->de, $tooEarly);
            $body = $this->call('POST', "/api/v1/orders/{$order->getId()}/dhl-shipments", $this->bookingInput($order));
            self::assertSame(409, $this->httpStatus(), $tooEarly);
            self::assertStringContainsString('in process, paid or ready for shipment', $body['message']);
        }

        $order = $this->order($this->de);
        $this->queueShipment(withInvoice: false);
        $this->call('POST', "/api/v1/orders/{$order->getId()}/dhl-shipments", $this->bookingInput($order));
        self::assertSame(201, $this->httpStatus());

        // A booked shipment is active; a second booking is refused before reaching DHL
        $requestsBefore = count($this->dhlRequests);
        $this->call('POST', "/api/v1/orders/{$order->getId()}/dhl-shipments", $this->bookingInput($order));
        self::assertSame(409, $this->httpStatus());
        self::assertCount($requestsBefore, $this->dhlRequests);

        // Customers can't use any of it
        $this->call('GET', "/api/v1/orders/{$order->getId()}/dhl-shipment/prefill", null, $this->customer);
        self::assertSame(403, $this->httpStatus());
        $this->call('POST', "/api/v1/orders/{$order->getId()}/dhl-shipments", [], $this->customer);
        self::assertSame(403, $this->httpStatus());
    }

    public function testCustomerCannotWriteTrackingFields(): void
    {
        $order = $this->order($this->de, Order::STATUS_DRAFT);
        $order->setIsDraft(true);
        $this->em->flush();

        $this->call('PATCH', "/api/v1/orders/{$order->getId()}", [
            'notes' => 'customer edit',
            'trackingNumber' => '9999999999',
            'trackingCarrier' => 'dhl',
            'trackingUrl' => 'https://evil.example',
        ], $this->customer, 'application/merge-patch+json');

        self::assertSame(200, $this->httpStatus(), (string) $this->client->getResponse()->getContent());
        $this->em->clear();
        $reloaded = $this->em->getRepository(Order::class)->find($order->getId());
        self::assertSame('customer edit', $reloaded->getNotes(), 'Other fields still save');
        self::assertNull($reloaded->getTrackingNumber());
        self::assertNull($reloaded->getTrackingUrl());
    }

    // ------------------------------------------------------------------

    private function bookingInput(Order $order): array
    {
        $prefillResponses = $this->dhlResponses;
        $this->queue('GET rates', ['products' => []]);
        $input = $this->call('GET', "/api/v1/orders/{$order->getId()}/dhl-shipment/prefill");
        // prefill's rate call must not consume responses queued for the booking
        $this->dhlResponses = $prefillResponses;
        array_pop($this->dhlRequests);
        $input['packages'] = [['weight' => 1.2, 'length' => 30, 'width' => 20, 'height' => 15]];

        return $input;
    }

    private function queueShipment(bool $withInvoice): string
    {
        $awb = (string) random_int(1000000000, 9999999999);
        $this->awbs[] = $awb;
        $documents = [['typeCode' => 'label', 'imageFormat' => 'PDF', 'content' => base64_encode('%PDF-1.4 label')]];
        if ($withInvoice) {
            $documents[] = ['typeCode' => 'invoice', 'imageFormat' => 'PDF', 'content' => base64_encode('%PDF-1.4 invoice')];
        }
        $this->queue('POST shipments', [
            'shipmentTrackingNumber' => $awb,
            'trackingUrl' => 'https://express.api.dhl.com/mydhlapi/test/shipments/' . $awb . '/tracking',
            'packages' => [['referenceNumber' => 1, 'trackingNumber' => 'JD0146' . $awb]],
            'documents' => $documents,
            'shipmentCharges' => [['currencyType' => 'BILLC', 'priceCurrency' => 'EUR', 'price' => 61.97]],
            'estimatedDeliveryDate' => ['estimatedDeliveryDate' => '2026-10-20T23:59:00Z', 'estimatedDeliveryType' => 'QDDC'],
        ], 201);

        return $awb;
    }

    private function queue(string $key, array $body, int $status = 200): void
    {
        $this->dhlResponses[$key][] = new MockResponse(json_encode($body), ['http_code' => $status]);
    }

    private function respond(string $method, string $url, array $options): MockResponse
    {
        $path = substr((string) parse_url($url, PHP_URL_PATH), strlen('/mydhlapi/test/'));
        $key = $method . ' ' . $path;
        $body = isset($options['body']) && is_string($options['body']) ? json_decode($options['body'], true) : null;
        $this->dhlRequests[] = ['method' => $method, 'path' => $path, 'body' => $body, 'url' => $url];

        $response = array_shift($this->dhlResponses[$key]);
        if (!$response) {
            self::fail('Unexpected DHL request: ' . $key);
        }

        return $response;
    }

    private function lastRequest(string $key): array
    {
        foreach (array_reverse($this->dhlRequests) as $request) {
            if ($request['method'] . ' ' . $request['path'] === $key) {
                return $request;
            }
        }
        self::fail('No DHL request ' . $key);
    }

    private function call(string $method, string $url, ?array $json = null, ?User $as = null, string $contentType = 'application/json'): array
    {
        $this->client->request($method, $url, [], [], [
            'HTTP_AUTHORIZATION' => 'Bearer ' . self::getContainer()->get(JWTTokenManagerInterface::class)->create($as ?? $this->admin),
            'HTTP_ACCEPT' => 'application/ld+json',
            'CONTENT_TYPE' => $contentType,
        ], $json !== null ? json_encode($json, JSON_THROW_ON_ERROR) : null);

        $body = json_decode((string) $this->client->getResponse()->getContent(), true);

        return is_array($body) ? $body : [];
    }

    private function httpStatus(): int
    {
        return $this->client->getResponse()->getStatusCode();
    }

    private function order(Country $destination, string $status = Order::STATUS_READY_FOR_SHIPMENT): Order
    {
        // Requests clear the entity manager; re-attach fixtures created in setUp
        $company = $this->em->find(Client::class, $this->company->getId());
        $destination = $this->em->find(Country::class, $destination->getId());
        $isSwiss = $destination->getCode() === 'CH';

        $address = (new Address())
            ->setClient($company)
            ->setEmail('receiving@example.com')
            ->setStreet($isSwiss ? 'Bahnhofstrasse 1' : 'Invalidenstrasse 1')
            ->setPostalCode($isSwiss ? '8001' : '10115')
            ->setCity($isSwiss ? 'Zurich' : 'Berlin')
            ->setCountry($destination)
            ->setIsDelivery(true);
        $this->em->persist($address);
        $this->em->flush();

        $order = (new Order())
            ->setOrderNumber('DB-' . $this->suffix . '-' . random_int(1000, 9999))
            ->setStatus($status)
            ->setTotalAmount(120.0)
            ->setUser($this->em->find(User::class, $this->customer->getId()))
            ->setDeliveryType($this->em->find(DeliveryType::class, $this->deliveryType->getId()))
            ->setShippingAddressId($address->getId());
        $order->setIsDraft(false);
        $order->setStatus($status);

        $item = (new OrderItem())->setProduct($this->em->find(Product::class, $this->product->getId()))->setQuantity(2)->setUnitPrice(60.0)->setSubtotal(120.0);
        $order->addItem($item);
        $this->em->persist($order);
        $this->em->flush();

        return $order;
    }

    private function country(string $code, string $name, bool $eu): Country
    {
        $country = $this->em->getRepository(Country::class)->findOneBy(['code' => $code]);
        if (!$country) {
            if (!$this->taxType) {
                $this->taxType = (new TaxType())->setName('DHL test VAT ' . $this->suffix)->setPercent('20.00');
                $this->em->persist($this->taxType);
            }
            $country = (new Country())->setCode($code)->setName($name)->setEuropeanUnion($eu)->setTaxType($this->taxType);
            $this->em->persist($country);
            $this->createdCountries[] = $country;
        }

        return $country;
    }

    private function user(string $name, array $roles, ?Client $company): User
    {
        $user = (new User())
            ->setEmail($name . '-' . $this->suffix)
            ->setFirstName('Test')
            ->setLastName(ucfirst($name));
        $user->setPassword('not-used');
        $user->setRoles($roles);
        if ($company) {
            $user->setClient($company);
        }
        $this->em->persist($user);

        return $user;
    }
}
