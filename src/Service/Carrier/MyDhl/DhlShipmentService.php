<?php

namespace App\Service\Carrier\MyDhl;

use App\Entity\Address;
use App\Entity\Client;
use App\Entity\Country;
use App\Entity\DhlShipment;
use App\Entity\Order;
use App\Entity\OrderItem;
use App\Entity\TrackingEvent;
use App\Entity\User;
use App\Entity\Warehouse;
use App\Enum\TrackingStatus;
use App\Repository\DhlShipmentRepository;
use App\Service\OrderLogService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Workflow\WorkflowInterface;

/**
 * Books DHL Express shipments for orders (admin flow, see tasks/todo.md "DHL Express"):
 *
 *  prefill()      → everything the booking modal needs, pre-filled from the order
 *  book()         → validate → MyDHL POST /shipments → store label/invoice → order "shipped"
 *  bookPickup()   → courier pickup for a booked shipment (separate call, so a failed
 *                   pickup window never loses the shipment — the legacy system's 5002/5003 errors)
 *  cancelPickup() / cancel()
 *
 * Request format verified against the MyDHL test API (Oct 2026): EU and dutiable
 * shipments, A4 and thermal labels, commercial invoice, pickups.
 */
class DhlShipmentService
{
    /** All shipping warehouses are in Austria; MyDHL wants local time + GMT offset of the origin. */
    private const SHIPPER_TIMEZONE = 'Europe/Vienna';

    /** EU product code → its dutiable (customs-declarable) counterpart. */
    private const DUTIABLE_PRODUCT = ['U' => 'P', 'T' => 'Y', 'K' => 'E', 'W' => 'H', '7' => '8', 'C' => 'Q'];
    /** EU product code → its domestic counterpart (same origin and destination country). */
    private const DOMESTIC_PRODUCT = ['U' => 'N', 'T' => '1', 'K' => 'I', 'W' => 'N'];
    private const DEFAULT_DOMESTIC_PRODUCT = 'N';

    private const DEFAULT_PRODUCT = 'U';
    private const DEFAULT_DESCRIPTION = 'Spare parts for plastics machinery';
    private const DEFAULT_INCOTERM = 'DAP';
    private const DEFAULT_PICKUP_READY = '13:00';
    private const DEFAULT_PICKUP_CLOSE = '16:00';
    private const DEFAULT_PICKUP_LOCATION = 'Reception';
    /** Time of day the parcel is ready at the warehouse (origin local time). */
    private const DEFAULT_SHIPPING_TIME = '13:00';
    /** MyDHL limit for one customer reference value. */
    private const REFERENCE_MAX = 35;

    /**
     * Booking happens once the ERP has scheduled the order (in process), before the
     * warehouse picks — the label is what the packer works with. Prepayment orders
     * wait until they are paid; "shipped" follows from DHL's pickup scan.
     */
    private const BOOKABLE_STATUSES = [Order::STATUS_IN_PROCESS, Order::STATUS_PAID, Order::STATUS_READY_FOR_SHIPMENT];

    private const LABEL_TEMPLATES = [
        DhlShipment::LABEL_FORMAT_A4 => 'ECOM26_A4_001',
        DhlShipment::LABEL_FORMAT_THERMAL => 'ECOM26_84_001', // 4x6" (8x4") thermal label
    ];

    private const INCOTERMS = ['EXW', 'FCA', 'CPT', 'CIP', 'DAP', 'DPU', 'DDP'];

    public function __construct(
        private readonly MyDhlClient $client,
        private readonly DhlLabelStorage $labelStorage,
        private readonly DhlShipmentRepository $shipmentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly WorkflowInterface $orderStateMachine,
        private readonly OrderLogService $orderLogService,
        private readonly LockFactory $lockFactory,
        private readonly LoggerInterface $logger,
        private readonly string $shipperEori = '',
        private readonly bool $paperlessTrade = false,
    ) {
    }

    // ------------------------------------------------------------------
    // Prefill
    // ------------------------------------------------------------------

    /**
     * Defaults for the booking modal. Never calls anything that books; the rate
     * lookup is best-effort (its failure is reported, not thrown).
     */
    public function prefill(Order $order): array
    {
        $shipper = $this->shipperFromWarehouse($this->resolveWarehouse($order));
        $receiver = $this->receiverFromOrder($order);
        $dutiable = $this->isCustomsDeclarable($shipper['countryCode'], $receiver['countryCode']);
        $productCode = $this->productCodeFor($order->getDeliveryType()?->getDhlProductCode(), $dutiable, $shipper['countryCode'] === $receiver['countryCode']);
        $shippingDate = $this->defaultShippingDate();
        $lineItems = $this->lineItemsFromOrder($order);
        $totalWeight = round(array_sum(array_map(static fn (array $l) => $l['netWeight'] ?? 0, $lineItems)), 3);

        $rates = [];
        $rateError = null;
        if ($receiver['countryCode'] && $receiver['cityName'] && $totalWeight > 0) {
            try {
                foreach ($this->client->getRates([
                    'originCountryCode' => $shipper['countryCode'],
                    'originCityName' => $shipper['cityName'],
                    'originPostalCode' => $shipper['postalCode'],
                    'destinationCountryCode' => $receiver['countryCode'],
                    'destinationCityName' => $receiver['cityName'],
                    'destinationPostalCode' => $receiver['postalCode'],
                    'weight' => $totalWeight,
                    'length' => 30, 'width' => 20, 'height' => 15,
                    'plannedShippingDate' => $shippingDate->format('Y-m-d'),
                    'isCustomsDeclarable' => $dutiable,
                ]) as $quote) {
                    $rates[] = [
                        'productCode' => $quote->productCode,
                        'productName' => $quote->productName,
                        'price' => $quote->price,
                        'currency' => $quote->currency,
                        'estimatedDeliveryAt' => $quote->estimatedDeliveryAt?->format(\DateTimeInterface::ATOM),
                        'pickupCutoffAt' => $quote->pickupCutoffAt?->format(\DateTimeInterface::ATOM),
                        'requiresAgreement' => $quote->requiresAgreement,
                    ];
                }
            } catch (MyDhlException $e) {
                $rateError = $e->getMessage();
            }
        }
        $productCode = $this->offeredProductCode($productCode, $rates);

        $prefill = [
            'shipper' => $shipper,
            'receiver' => $receiver,
            'isCustomsDeclarable' => $dutiable,
            'productCode' => $productCode,
            'plannedShippingDate' => $shippingDate->format('Y-m-d'),
            'plannedShippingTime' => self::DEFAULT_SHIPPING_TIME,
            'reference' => '',
            'labelFormat' => DhlShipment::LABEL_FORMAT_A4,
            'packages' => [['weight' => $totalWeight > 0 ? $totalWeight : null, 'length' => null, 'width' => null, 'height' => null]],
            'description' => self::DEFAULT_DESCRIPTION,
            'incoterm' => self::DEFAULT_INCOTERM,
            'currency' => $order->getCurrency(),
            'declaredValue' => round(array_sum(array_map(static fn (array $l) => $l['unitPrice'] * $l['quantity'], $lineItems)), 2),
            'lineItems' => $lineItems,
            'pickup' => [
                'requested' => false,
                'readyTime' => self::DEFAULT_PICKUP_READY,
                'closeTime' => self::DEFAULT_PICKUP_CLOSE,
                'location' => self::DEFAULT_PICKUP_LOCATION,
            ],
            'rates' => $rates,
            'rateError' => $rateError,
            'isTestEnvironment' => $this->client->isTestEnvironment(),
        ];
        $prefill['issues'] = $this->validate($this->normalize($prefill), checkPackages: false);

        return $prefill;
    }

    // ------------------------------------------------------------------
    // Booking
    // ------------------------------------------------------------------

    /**
     * Books the shipment with DHL and moves the order to "ready for shipment";
     * "shipped" follows from DHL's pickup scan (TrackingService) or the admin.
     * A booking DHL refuses is kept as a failed shipment (recordFailedBooking).
     *
     * @param array $input the (possibly admin-edited) prefill structure
     * @throws DhlBookingException invalid input / wrong order state
     * @throws MyDhlException      DHL rejected or was unreachable
     */
    public function book(Order $order, array $input, User $user): DhlShipment
    {
        $lock = $this->lockFactory->createLock('dhl-booking-order-' . $order->getId(), 120);
        if (!$lock->acquire()) {
            throw DhlBookingException::conflict('A DHL booking for this order is already in progress.');
        }

        try {
            $this->entityManager->refresh($order);
            $this->assertBookable($order);

            $data = $this->normalize($input);
            $data['isCustomsDeclarable'] = $this->isCustomsDeclarable($data['shipper']['countryCode'], $data['receiver']['countryCode']);
            if ($errors = $this->validate($data)) {
                throw DhlBookingException::invalid($errors);
            }

            try {
                $result = $this->client->createShipment($this->buildShipmentPayload($order, $data));
            } catch (MyDhlException $e) {
                $this->recordFailedBooking($order, $data, $input, $user, $e);
                throw $e;
            }

            // DHL has booked it — from here on, never lose the AWB, even if our side fails.
            $this->logger->info('DHL shipment booked', ['order_id' => $order->getId(), 'awb' => $result->awb]);

            $shipment = (new DhlShipment())
                ->setOrder($order)
                ->setAwb($result->awb)
                ->setProductCode($data['productCode'])
                ->setProductName($this->productNameFromRates($input, $data['productCode']))
                ->setPlannedShippingAt($this->plannedAt($data['plannedShippingDate'], $data['plannedShippingTime']))
                ->setReference($data['reference'] !== '' ? $data['reference'] : null)
                ->setIsCustomsDeclarable($data['isCustomsDeclarable'])
                ->setPackages($this->packagesWithTracking($data['packages'], $result->pieceTrackingNumbers))
                ->setTotalWeight(number_format(array_sum(array_column($data['packages'], 'weight')), 3, '.', ''))
                ->setShipper($data['shipper'])
                ->setReceiver($data['receiver'])
                ->setLabelFormat($data['labelFormat'])
                ->setPrice($result->price !== null ? number_format($result->price, 2, '.', '') : null)
                ->setCurrency($result->currency)
                ->setEstimatedDeliveryAt($result->estimatedDeliveryAt)
                ->setWarnings($result->warnings)
                ->setCreatedBy($user);

            foreach ($result->documents as $type => $bytes) {
                $shipment->addDocument($type, $this->labelStorage->save($result->awb, $type, $bytes));
            }

            try {
                $this->entityManager->wrapInTransaction(function () use ($order, $shipment, $data) {
                    $this->entityManager->persist($shipment);
                    if ($data['isCustomsDeclarable']) {
                        $this->rememberCustomsData($order, $data['lineItems']);
                    }

                    $order->setTrackingNumber($shipment->getAwb());
                    $order->setTrackingCarrier('dhl');
                    $order->setTrackingUrl($order->generateTrackingUrl());

                    // First timeline entry; DHL's own checkpoints follow once the parcel is scanned.
                    $order->addTrackingEvent((new TrackingEvent())
                        ->setStatus(TrackingStatus::CREATED->value)
                        ->setDescription('Shipment booked with DHL Express, label created')
                        ->setLocation($data['shipper']['cityName'])
                        ->setOccurredAt(new \DateTime())
                        ->setSource(TrackingEvent::SOURCE_DHL_API));

                    // Label created = ready for the warehouse to pack. "shipped" comes later, from
                    // DHL's pickup scan or the admin. The transition dispatches the status email;
                    // the doctrine messenger transport shares this connection, so the message only
                    // becomes visible to the worker on commit — with the tracking number in place.
                    if ($this->orderStateMachine->can($order, 'ready_to_ship')) {
                        $this->orderStateMachine->apply($order, 'ready_to_ship');
                    }

                    $this->entityManager->flush();
                });
            } catch (\Throwable $e) {
                $this->logger->critical('DHL shipment booked but saving it failed — record the AWB manually', [
                    'order_id' => $order->getId(), 'awb' => $result->awb, 'error' => $e->getMessage(),
                ]);
                throw $e;
            }

            if ($data['pickup']['requested']) {
                try {
                    $this->bookPickup($shipment, $data['pickup'], $data['plannedShippingDate']);
                } catch (MyDhlException) {
                    // Stored on the shipment (pickupError); the booking itself succeeded.
                }
            }

            return $shipment;
        } finally {
            $lock->release();
        }
    }

    // ------------------------------------------------------------------
    // Pickup
    // ------------------------------------------------------------------

    /**
     * @param array{readyTime?: string, closeTime?: string, location?: string} $pickup
     * @throws MyDhlException after recording the error on the shipment
     */
    public function bookPickup(DhlShipment $shipment, array $pickup, ?string $date = null): DhlShipment
    {
        if ($shipment->getStatus() !== DhlShipment::STATUS_BOOKED) {
            throw DhlBookingException::conflict('The shipment is cancelled.');
        }
        if ($shipment->getPickupConfirmationNumber() && !$shipment->getPickupCancelledAt()) {
            throw DhlBookingException::conflict('A pickup is already booked for this shipment. Cancel it first.');
        }

        $readyTime = $this->time($pickup['readyTime'] ?? null) ?? self::DEFAULT_PICKUP_READY;
        $closeTime = $this->time($pickup['closeTime'] ?? null) ?? self::DEFAULT_PICKUP_CLOSE;
        $location = trim((string) ($pickup['location'] ?? '')) ?: self::DEFAULT_PICKUP_LOCATION;
        $date ??= $this->date($pickup['date'] ?? null) ?? $shipment->getPlannedShippingAt()?->format('Y-m-d');

        $errors = [];
        if (!$date) {
            $errors[] = 'Pickup date is required.';
        }
        if ($readyTime >= $closeTime) {
            $errors[] = 'Pickup ready time must be before the close time.';
        }
        if ($errors) {
            throw DhlBookingException::invalid($errors);
        }

        $readyAt = $this->plannedAt($date, $readyTime);
        $payload = [
            'plannedPickupDateAndTime' => $this->dhlDateTime($readyAt),
            'closeTime' => $closeTime,
            'location' => mb_substr($location, 0, 80),
            'locationType' => 'business',
            'customerDetails' => ['shipperDetails' => $this->partyPayload($shipment->getShipper())],
            'shipmentDetails' => [[
                'productCode' => $shipment->getProductCode(),
                'isCustomsDeclarable' => $shipment->getIsCustomsDeclarable(),
                'unitOfMeasurement' => 'metric',
                'packages' => array_map(fn (array $p) => $this->packagePayload($p), $shipment->getPackages()),
            ]],
        ];

        try {
            $result = $this->client->createPickup($payload);
        } catch (MyDhlException $e) {
            $shipment->setPickupError($e->getMessage());
            $this->entityManager->flush();
            throw $e;
        }

        $shipment
            ->setPickupConfirmationNumber($result->getConfirmationNumber())
            ->setPickupReadyAt($readyAt)
            ->setPickupCloseTime($closeTime)
            ->setPickupCancelledAt(null)
            ->setPickupError(null);
        $this->entityManager->flush();

        return $shipment;
    }

    public function cancelPickup(DhlShipment $shipment, User $user): DhlShipment
    {
        if (!$shipment->getPickupConfirmationNumber() || $shipment->getPickupCancelledAt()) {
            throw DhlBookingException::conflict('There is no active pickup for this shipment.');
        }

        $this->client->cancelPickup($shipment->getPickupConfirmationNumber(), $this->requestorName($user));
        $shipment->setPickupCancelledAt(new \DateTime());
        $this->entityManager->flush();

        return $shipment;
    }

    // ------------------------------------------------------------------
    // Cancel
    // ------------------------------------------------------------------

    /**
     * MyDHL has no "void shipment" call: an Express AWB is only billed once DHL
     * scans the parcel. Cancelling here cancels the pickup, marks the shipment
     * cancelled. The order keeps "ready for shipment" (an order already marked shipped by
     * hand goes back to it), so a new shipment can be requested.
     */
    public function cancel(DhlShipment $shipment, User $user): DhlShipment
    {
        if ($shipment->getStatus() !== DhlShipment::STATUS_BOOKED) {
            throw DhlBookingException::conflict('The shipment is already cancelled.');
        }

        $order = $shipment->getOrder();
        foreach ($order->getTrackingEvents() as $event) {
            if ($event->getSource() === TrackingEvent::SOURCE_DHL_API
                && $event->getStatus() !== TrackingStatus::CREATED->value
                && $order->getTrackingNumber() === $shipment->getAwb()) {
                throw DhlBookingException::conflict('DHL has already picked up or moved this parcel — it can no longer be cancelled here. Contact DHL.');
            }
        }

        // Courier must not come for a cancelled shipment; abort if DHL refuses.
        if ($shipment->getPickupConfirmationNumber() && !$shipment->getPickupCancelledAt()) {
            $this->cancelPickup($shipment, $user);
        }

        $shipment->setStatus(DhlShipment::STATUS_CANCELLED)->setCancelledAt(new \DateTime());

        if ($order->getTrackingNumber() === $shipment->getAwb()) {
            $order->setTrackingNumber(null);
            $order->setTrackingUrl(null);
            $order->setTrackingCarrier(null);
            $order->setDispatchedAt(null);
            $order->setDispatchedBy(null);
            // Only "label created" entries can exist here (guard above); they belong to the cancelled AWB
            foreach ($order->getTrackingEvents()->toArray() as $event) {
                if ($event->getSource() === TrackingEvent::SOURCE_DHL_API) {
                    $order->removeTrackingEvent($event);
                }
            }

            if ($order->getStatus() === Order::STATUS_SHIPPED) {
                // No workflow transition leads back; set directly and log (no customer email).
                $order->setStatus(Order::STATUS_READY_FOR_SHIPMENT);
                $this->orderLogService->logStatusChange(
                    $order,
                    Order::STATUS_SHIPPED,
                    Order::STATUS_READY_FOR_SHIPMENT,
                    sprintf('DHL shipment %s cancelled by %s', $shipment->getAwb(), $user->getEmail()),
                    ['dhl_awb' => $shipment->getAwb()]
                );
            }
        }

        $this->entityManager->flush();

        return $shipment;
    }

    // ------------------------------------------------------------------
    // Order → booking data
    // ------------------------------------------------------------------

    private function assertBookable(Order $order): void
    {
        if (!in_array($order->getStatus(), self::BOOKABLE_STATUSES, true)) {
            throw DhlBookingException::conflict(sprintf(
                'Only orders in process, paid or ready for shipment can be booked with DHL (this one is "%s").',
                $order->getStatus()
            ));
        }
        if ($this->shipmentRepository->findActiveForOrder($order)) {
            throw DhlBookingException::conflict('This order already has an active DHL shipment. Cancel it before booking a new one.');
        }
        if (!$this->client->isConfigured()) {
            throw DhlBookingException::conflict('DHL Express is not configured on this server.');
        }
    }

    /** References DHL prints on the label: the warehouse's own first, then our order number. */
    private function customerReferences(Order $order, array $data): array
    {
        $references = [];
        if ($data['reference'] !== '') {
            $references[] = ['value' => $data['reference'], 'typeCode' => 'CU'];
        }
        $references[] = ['value' => mb_substr((string) $order->getOrderNumber(), 0, self::REFERENCE_MAX), 'typeCode' => 'CU'];

        return $references;
    }

    /**
     * DHL refused the booking: keep what was attempted and why, so the shipments
     * list shows it and the admin can correct the data and request again.
     */
    private function recordFailedBooking(Order $order, array $data, array $input, User $user, MyDhlException $e): void
    {
        $message = $e->getMessage();
        if ($e->getDetails() !== []) {
            $message .= ' — ' . implode(' ', array_map('strval', $e->getDetails()));
        }

        $failed = (new DhlShipment())
            ->setOrder($order)
            ->setStatus(DhlShipment::STATUS_FAILED)
            ->setErrorMessage($message)
            ->setReference($data['reference'] !== '' ? $data['reference'] : null)
            ->setProductCode($data['productCode'])
            ->setProductName($this->productNameFromRates($input, $data['productCode']))
            ->setPlannedShippingAt($this->plannedAt($data['plannedShippingDate'], $data['plannedShippingTime']))
            ->setIsCustomsDeclarable($data['isCustomsDeclarable'])
            ->setPackages($data['packages'])
            ->setTotalWeight(number_format(array_sum(array_column($data['packages'], 'weight')), 3, '.', ''))
            ->setShipper($data['shipper'])
            ->setReceiver($data['receiver'])
            ->setLabelFormat($data['labelFormat'])
            ->setCreatedBy($user);

        $this->entityManager->persist($failed);
        $this->entityManager->flush();
    }

    private function resolveWarehouse(Order $order): ?Warehouse
    {
        return $order->getDeliveryType()?->getWarehouse()
            ?? $this->entityManager->getRepository(Warehouse::class)->findOneBy(['isActive' => true], ['id' => 'ASC']);
    }

    private function shipperFromWarehouse(?Warehouse $warehouse): array
    {
        return [
            'companyName' => 'Starlinger & Co Gesellschaft m.b.H.',
            'fullName' => $warehouse?->getContactPerson() ?: ($warehouse?->getName() ?? ''),
            'addressLine1' => $warehouse?->getAddress() ?? '',
            'addressLine2' => '',
            'postalCode' => $warehouse?->getPostalCode() ?? '',
            'cityName' => $warehouse?->getCity() ?? '',
            'countryCode' => $warehouse?->getCountry()?->getCode() ?? '',
            'email' => $warehouse?->getEmail() ?? '',
            'phone' => $warehouse?->getPhone() ?? '',
            'vatNumber' => '',
        ];
    }

    private function receiverFromOrder(Order $order): array
    {
        $address = $this->resolveShippingAddress($order);
        $company = $address?->getClient() ?? $order->getOnBehalfOfClient() ?? $order->getUser()?->getClient();
        $user = $order->getUser();

        return [
            'companyName' => $company?->getName() ?? '',
            'fullName' => $user?->getFullName() ?: ($address?->getName() ?? ''),
            'addressLine1' => $address?->getStreet() ?? '',
            'addressLine2' => '',
            'postalCode' => $address?->getPostalCode() ?? '',
            'cityName' => $address?->getCity() ?? '',
            'countryCode' => $address?->getCountry()?->getCode() ?? '',
            'email' => $address?->getEmail() ?: ($user?->getEmail() ?: ($company?->getEmail() ?? '')),
            'phone' => $address?->getPhone() ?: ($user?->getPhoneNumber() ?: ($company?->getPhoneNumber() ?? '')),
            'vatNumber' => $company?->getVatNumber() ?? '',
        ];
    }

    private function resolveShippingAddress(Order $order): ?Address
    {
        if ($order->getShippingAddressId()) {
            $address = $this->entityManager->getRepository(Address::class)->find($order->getShippingAddressId());
            if ($address) {
                return $address;
            }
        }

        $client = $order->getOnBehalfOfClient() ?? $order->getUser()?->getClient();
        if (!$client instanceof Client) {
            return null;
        }

        return $this->entityManager->getRepository(Address::class)->findOneBy(
            ['client' => $client, 'isDelivery' => true, 'isActive' => true],
            ['id' => 'ASC']
        );
    }

    private function lineItemsFromOrder(Order $order): array
    {
        $lines = [];
        /** @var OrderItem $item */
        foreach ($order->getItems() as $item) {
            $product = $item->getProduct();
            $quantity = max(1, $item->getQuantity());
            $unitWeight = $product?->getWeightKg();

            $lines[] = [
                'orderItemId' => $item->getId(),
                'partNo' => $product?->getPartNo(),
                'description' => mb_substr((string) ($product?->getName() ?? 'Spare part'), 0, 70),
                'quantity' => $quantity,
                // Price actually charged (after discounts)
                'unitPrice' => round($item->getSubtotal() / $quantity, 2),
                'hsCode' => $product?->getHsCode() ?? '',
                'countryOfOrigin' => $product?->getCountryOfOrigin() ?? '',
                'netWeight' => $unitWeight !== null ? round($unitWeight * $quantity, 3) : null,
            ];
        }

        return $lines;
    }

    /**
     * Admin-entered HS code / origin are saved to products that had none, so each
     * product only ever needs entering once.
     */
    private function rememberCustomsData(Order $order, array $lineItems): void
    {
        $items = [];
        foreach ($order->getItems() as $item) {
            $items[$item->getId()] = $item;
        }

        foreach ($lineItems as $line) {
            $product = ($items[$line['orderItemId'] ?? 0] ?? null)?->getProduct();
            if (!$product) {
                continue;
            }
            if (!$product->getHsCode() && $line['hsCode']) {
                $product->setHsCode($line['hsCode']);
            }
            if (!$product->getCountryOfOrigin() && $line['countryOfOrigin']) {
                $product->setCountryOfOrigin($line['countryOfOrigin']);
            }
        }
    }

    // ------------------------------------------------------------------
    // Input normalisation + validation
    // ------------------------------------------------------------------

    private function normalize(array $input): array
    {
        $party = function (mixed $p): array {
            $p = is_array($p) ? $p : [];
            $out = [];
            foreach (['companyName', 'fullName', 'addressLine1', 'addressLine2', 'postalCode', 'cityName', 'countryCode', 'email', 'phone', 'vatNumber'] as $key) {
                $out[$key] = trim((string) ($p[$key] ?? ''));
            }
            $out['countryCode'] = strtoupper($out['countryCode']);
            return $out;
        };

        $packages = [];
        foreach ((array) ($input['packages'] ?? []) as $p) {
            $packages[] = [
                'weight' => $this->positiveFloat($p['weight'] ?? null),
                'length' => $this->positiveFloat($p['length'] ?? null),
                'width' => $this->positiveFloat($p['width'] ?? null),
                'height' => $this->positiveFloat($p['height'] ?? null),
            ];
        }

        $lineItems = [];
        foreach ((array) ($input['lineItems'] ?? []) as $l) {
            $lineItems[] = [
                'orderItemId' => isset($l['orderItemId']) ? (int) $l['orderItemId'] : null,
                'description' => mb_substr(trim((string) ($l['description'] ?? '')), 0, 70),
                'quantity' => max(0, (int) ($l['quantity'] ?? 0)),
                'unitPrice' => round((float) ($l['unitPrice'] ?? 0), 2),
                'hsCode' => preg_replace('/[^0-9]/', '', (string) ($l['hsCode'] ?? '')),
                'countryOfOrigin' => strtoupper(trim((string) ($l['countryOfOrigin'] ?? ''))),
                'netWeight' => $this->positiveFloat($l['netWeight'] ?? null),
            ];
        }

        $pickup = (array) ($input['pickup'] ?? []);

        return [
            'shipper' => $party($input['shipper'] ?? null),
            'receiver' => $party($input['receiver'] ?? null),
            'isCustomsDeclarable' => (bool) ($input['isCustomsDeclarable'] ?? false),
            'productCode' => strtoupper(trim((string) ($input['productCode'] ?? ''))),
            'plannedShippingDate' => $this->date($input['plannedShippingDate'] ?? null),
            'plannedShippingTime' => $this->time($input['plannedShippingTime'] ?? null) ?? self::DEFAULT_SHIPPING_TIME,
            'reference' => mb_substr(trim((string) ($input['reference'] ?? '')), 0, self::REFERENCE_MAX),
            'labelFormat' => (string) ($input['labelFormat'] ?? DhlShipment::LABEL_FORMAT_A4),
            'packages' => $packages,
            'description' => mb_substr(trim((string) ($input['description'] ?? '')), 0, 70) ?: self::DEFAULT_DESCRIPTION,
            'incoterm' => strtoupper(trim((string) ($input['incoterm'] ?? self::DEFAULT_INCOTERM))),
            'currency' => strtoupper(trim((string) ($input['currency'] ?? 'EUR'))) ?: 'EUR',
            'lineItems' => $lineItems,
            'pickup' => [
                'requested' => (bool) ($pickup['requested'] ?? false),
                'readyTime' => $this->time($pickup['readyTime'] ?? null) ?? self::DEFAULT_PICKUP_READY,
                'closeTime' => $this->time($pickup['closeTime'] ?? null) ?? self::DEFAULT_PICKUP_CLOSE,
                'location' => trim((string) ($pickup['location'] ?? '')) ?: self::DEFAULT_PICKUP_LOCATION,
            ],
        ];
    }

    /**
     * @return string[] human-readable problems; empty when bookable
     */
    private function validate(array $data, bool $checkPackages = true): array
    {
        $errors = [];

        foreach (['shipper' => 'Shipper', 'receiver' => 'Receiver'] as $key => $label) {
            $p = $data[$key];
            $required = [
                'companyName' => 'company name', 'fullName' => 'contact name', 'addressLine1' => 'street',
                'postalCode' => 'postal code', 'cityName' => 'city', 'countryCode' => 'country',
                'email' => 'email', 'phone' => 'phone',
            ];
            foreach ($required as $field => $name) {
                if ($p[$field] === '') {
                    $errors[] = sprintf('%s %s is missing.', $label, $name);
                }
            }
            if ($p['countryCode'] !== '' && !preg_match('/^[A-Z]{2}$/', $p['countryCode'])) {
                $errors[] = sprintf('%s country must be a 2-letter ISO code.', $label);
            }
            if ($p['email'] !== '' && !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) {
                $errors[] = sprintf('%s email is not valid.', $label);
            }
        }

        if (!preg_match('/^[A-Z0-9]$/', $data['productCode'])) {
            $errors[] = 'Choose a DHL product.';
        }
        if (!$data['plannedShippingDate']) {
            $errors[] = 'Shipping date is required.';
        } elseif ($data['plannedShippingDate'] < (new \DateTimeImmutable('today', new \DateTimeZone(self::SHIPPER_TIMEZONE)))->format('Y-m-d')) {
            $errors[] = 'Shipping date cannot be in the past.';
        }
        if (!isset(self::LABEL_TEMPLATES[$data['labelFormat']])) {
            $errors[] = 'Label format must be "a4" or "thermal".';
        }

        if ($checkPackages) {
            if (!$data['packages']) {
                $errors[] = 'Add at least one package.';
            }
            foreach ($data['packages'] as $i => $p) {
                foreach (['weight' => 'weight (kg)', 'length' => 'length (cm)', 'width' => 'width (cm)', 'height' => 'height (cm)'] as $field => $name) {
                    if ($p[$field] === null) {
                        $errors[] = sprintf('Package %d: %s is required.', $i + 1, $name);
                    }
                }
            }
        }

        if ($data['pickup']['requested'] && $data['pickup']['readyTime'] >= $data['pickup']['closeTime']) {
            $errors[] = 'Pickup ready time must be before the close time.';
        }

        if ($data['isCustomsDeclarable']) {
            if (!in_array($data['incoterm'], self::INCOTERMS, true)) {
                $errors[] = 'Choose an incoterm (e.g. DAP).';
            }
            if (!$data['lineItems']) {
                $errors[] = 'Customs: at least one line item is required.';
            }
            $missingHs = $missingOrigin = $missingWeight = [];
            foreach ($data['lineItems'] as $i => $l) {
                $name = $l['description'] ?: ('line ' . ($i + 1));
                if (!preg_match('/^[0-9]{6,10}$/', $l['hsCode'])) {
                    $missingHs[] = $name;
                }
                if (!preg_match('/^[A-Z]{2}$/', $l['countryOfOrigin'])) {
                    $missingOrigin[] = $name;
                }
                if ($l['netWeight'] === null) {
                    $missingWeight[] = $name;
                }
                if ($l['quantity'] < 1 || $l['unitPrice'] <= 0) {
                    $errors[] = sprintf('Customs: "%s" needs a quantity and a unit price.', $name);
                }
            }
            if ($missingHs) {
                $errors[] = 'Customs: HS code (6-10 digits) missing for ' . implode(', ', $missingHs) . '.';
            }
            if ($missingOrigin) {
                $errors[] = 'Customs: country of origin missing for ' . implode(', ', $missingOrigin) . '.';
            }
            if ($missingWeight) {
                $errors[] = 'Customs: net weight missing for ' . implode(', ', $missingWeight) . '.';
            }
        }

        return $errors;
    }

    // ------------------------------------------------------------------
    // MyDHL payloads
    // ------------------------------------------------------------------

    private function buildShipmentPayload(Order $order, array $data): array
    {
        $dutiable = $data['isCustomsDeclarable'];
        $plannedAt = $this->plannedAt($data['plannedShippingDate'], $data['plannedShippingTime']);

        $imageOptions = [
            ['typeCode' => 'label', 'templateName' => self::LABEL_TEMPLATES[$data['labelFormat']]],
        ];
        if ($dutiable) {
            $imageOptions[] = [
                'typeCode' => 'invoice',
                'templateName' => 'COMMERCIAL_INVOICE_P_10',
                'isRequested' => true,
                'invoiceType' => 'commercial',
            ];
        }

        $shipper = $this->partyPayload($data['shipper']);
        if ($this->shipperEori !== '') {
            $shipper['registrationNumbers'] = [[
                'typeCode' => 'EOR', 'number' => $this->shipperEori, 'issuerCountryCode' => $data['shipper']['countryCode'],
            ]];
        }
        $receiver = $this->partyPayload($data['receiver']);
        if ($data['receiver']['vatNumber'] !== '') {
            $receiver['registrationNumbers'] = [[
                'typeCode' => 'VAT', 'number' => $data['receiver']['vatNumber'], 'issuerCountryCode' => $data['receiver']['countryCode'],
            ]];
        }

        $payload = [
            'plannedShippingDateAndTime' => $this->dhlDateTime($plannedAt),
            // Pickup is booked separately (bookPickup) so a rejected pickup window
            // never blocks the shipment itself.
            'pickup' => ['isRequested' => false],
            'productCode' => $data['productCode'],
            'getRateEstimates' => true,
            'estimatedDeliveryDate' => ['isRequested' => true, 'typeCode' => 'QDDC'],
            'outputImageProperties' => [
                'encodingFormat' => 'pdf',
                'imageOptions' => $imageOptions,
                // One printable label PDF (transport label + waybill copy)
                'splitTransportAndWaybillDocLabels' => false,
                'allDocumentsInOneImage' => false,
            ],
            'customerReferences' => $this->customerReferences($order, $data),
            'customerDetails' => ['shipperDetails' => $shipper, 'receiverDetails' => $receiver],
            'content' => [
                'packages' => array_map(fn (array $p) => $this->packagePayload($p), $data['packages']),
                'isCustomsDeclarable' => $dutiable,
                'description' => $data['description'],
                'incoterm' => $dutiable ? $data['incoterm'] : self::DEFAULT_INCOTERM,
                'unitOfMeasurement' => 'metric',
            ],
        ];

        if ($dutiable) {
            $lineItems = [];
            $declared = 0.0;
            foreach (array_values($data['lineItems']) as $i => $l) {
                $declared += $l['unitPrice'] * $l['quantity'];
                $lineItems[] = [
                    'number' => $i + 1,
                    'description' => $l['description'],
                    'price' => $l['unitPrice'],
                    'quantity' => ['value' => $l['quantity'], 'unitOfMeasurement' => 'PCS'],
                    'commodityCodes' => [['typeCode' => 'outbound', 'value' => $l['hsCode']]],
                    'exportReasonType' => 'permanent',
                    'manufacturerCountry' => $l['countryOfOrigin'],
                    'weight' => ['netValue' => $l['netWeight'], 'grossValue' => $l['netWeight']],
                ];
            }

            $payload['content']['declaredValue'] = round($declared, 2);
            $payload['content']['declaredValueCurrency'] = $data['currency'];
            $payload['content']['exportDeclaration'] = [
                'lineItems' => $lineItems,
                'invoice' => [
                    'number' => mb_substr((string) $order->getOrderNumber(), 0, 35),
                    'date' => $plannedAt->format('Y-m-d'),
                ],
                'exportReason' => 'Sale',
            ];

            if ($this->paperlessTrade) {
                // DHL receives the invoice electronically — no printed copy on the parcel.
                // Must be enabled on the DHL account, otherwise DHL rejects the booking.
                $payload['valueAddedServices'] = [['serviceCode' => 'WY']];
            }
        }

        return $payload;
    }

    private function partyPayload(array $p): array
    {
        $address = array_filter([
            'postalCode' => $p['postalCode'] ?? '',
            'cityName' => $p['cityName'] ?? '',
            'countryCode' => $p['countryCode'] ?? '',
            'addressLine1' => mb_substr((string) ($p['addressLine1'] ?? ''), 0, 45),
            'addressLine2' => mb_substr((string) ($p['addressLine2'] ?? ''), 0, 45),
        ], static fn ($v) => $v !== '');

        return [
            'postalAddress' => $address,
            'contactInformation' => [
                'email' => $p['email'] ?? '',
                'phone' => $p['phone'] ?? '',
                'companyName' => mb_substr((string) ($p['companyName'] ?? ''), 0, 100),
                'fullName' => mb_substr((string) ($p['fullName'] ?? ''), 0, 255),
            ],
        ];
    }

    private function packagePayload(array $p): array
    {
        return [
            'weight' => round((float) $p['weight'], 3),
            'dimensions' => [
                'length' => round((float) $p['length'], 1),
                'width' => round((float) $p['width'], 1),
                'height' => round((float) $p['height'], 1),
            ],
        ];
    }

    private function packagesWithTracking(array $packages, array $pieceTrackingNumbers): array
    {
        foreach ($packages as $i => $p) {
            $packages[$i]['trackingNumber'] = $pieceTrackingNumbers[$i] ?? null;
        }

        return $packages;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function isCustomsDeclarable(string $originCode, string $destinationCode): bool
    {
        if ($originCode === '' || $destinationCode === '' || $originCode === $destinationCode) {
            return false;
        }

        $repo = $this->entityManager->getRepository(Country::class);
        $origin = $repo->findOneBy(['code' => $originCode]);
        $destination = $repo->findOneBy(['code' => $destinationCode]);

        // Unknown country → treat as non-EU (customs data is the safe side)
        return !($origin?->isEuropeanUnion() && $destination?->isEuropeanUnion());
    }

    /**
     * The delivery type names an EU product (U, T, K, W, …); DHL sells the same
     * service under a different code for dutiable (P, Y, E, H) and for domestic
     * (N, 1, I) shipments — "U" to an Austrian address is refused with error 1001.
     */
    private function productCodeFor(?string $configured, bool $dutiable, bool $domestic = false): string
    {
        $code = $configured ?: self::DEFAULT_PRODUCT;
        $base = array_search($code, self::DUTIABLE_PRODUCT, true) ?: $code;

        if ($domestic) {
            return self::DOMESTIC_PRODUCT[$base] ?? self::DEFAULT_DOMESTIC_PRODUCT;
        }

        return $dutiable ? (self::DUTIABLE_PRODUCT[$base] ?? $base) : $base;
    }

    /**
     * When DHL quoted products for this lane, the default must be one of them:
     * keep the computed code if it was quoted, else take the cheapest priced
     * product that needs no special agreement. No quotes → keep the code.
     *
     * @param array<int, array{productCode: string, price: ?float, requiresAgreement: bool}> $rates
     */
    private function offeredProductCode(string $code, array $rates): string
    {
        if ($rates === [] || in_array($code, array_column($rates, 'productCode'), true)) {
            return $code;
        }

        $bookable = array_filter($rates, static fn (array $r) => $r['price'] !== null && !$r['requiresAgreement']);
        usort($bookable, static fn (array $a, array $b) => $a['price'] <=> $b['price']);

        return $bookable[0]['productCode'] ?? $code;
    }

    private function productNameFromRates(array $input, string $code): ?string
    {
        foreach ((array) ($input['rates'] ?? []) as $rate) {
            if (($rate['productCode'] ?? null) === $code) {
                return (string) ($rate['productName'] ?? '') ?: null;
            }
        }

        return null;
    }

    /** Today before noon (time to pack + book a same-day pickup), else the next weekday. */
    private function defaultShippingDate(): \DateTimeImmutable
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone(self::SHIPPER_TIMEZONE));
        $date = (int) $now->format('G') < 12 ? $now->setTime(0, 0) : $now->modify('+1 day')->setTime(0, 0);
        while ((int) $date->format('N') >= 6) {
            $date = $date->modify('+1 day');
        }

        return $date;
    }

    private function plannedAt(string $date, string $time): \DateTimeImmutable
    {
        return new \DateTimeImmutable($date . ' ' . $time, new \DateTimeZone(self::SHIPPER_TIMEZONE));
    }

    /** MyDHL format: "2026-10-19T13:00:00 GMT+02:00" (origin local time). */
    private function dhlDateTime(\DateTimeImmutable $at): string
    {
        return $at->format('Y-m-d\TH:i:s') . ' GMT' . $at->format('P');
    }

    private function requestorName(User $user): string
    {
        return mb_substr(trim($user->getFullName()) ?: (string) $user->getEmail(), 0, 45);
    }

    private function positiveFloat(mixed $value): ?float
    {
        if ($value === null || $value === '') {
            return null;
        }
        $value = (float) str_replace(',', '.', (string) $value);

        return $value > 0 ? $value : null;
    }

    private function date(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) && \DateTimeImmutable::createFromFormat('!Y-m-d', $value) ? $value : null;
    }

    private function time(mixed $value): ?string
    {
        $value = trim((string) $value);

        return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) ? $value : null;
    }
}
