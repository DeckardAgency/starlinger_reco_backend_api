<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\DhlShipmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\SerializedName;

/**
 * A DHL Express (MyDHL API) shipment booked for an order: AWB, label/invoice
 * documents, optional courier pickup, and the shipper/receiver data as sent.
 *
 * An order can have several (re-booking after a cancel, or split shipments);
 * the order's trackingNumber points at the active one. Admin-only — customers
 * see tracking through the order itself.
 *
 * Writes go through the booking endpoints (OrderDhlShipmentController), never
 * through API Platform, so only read operations are exposed here.
 */
#[ORM\Entity(repositoryClass: DhlShipmentRepository::class)]
#[ORM\Table(name: 'dhl_shipment')]
#[ORM\Index(name: 'idx_dhl_shipment_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_dhl_shipment_awb', columns: ['awb'])]
#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_ADMIN')"),
        new Get(security: "is_granted('ROLE_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['dhl_shipment:read']],
    order: ['createdAt' => 'DESC']
)]
#[ApiFilter(SearchFilter::class, properties: ['order' => 'exact', 'order.id' => 'exact', 'awb' => 'exact', 'status' => 'exact'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt'])]
class DhlShipment
{
    public const STATUS_BOOKED = 'booked';
    public const STATUS_CANCELLED = 'cancelled';

    public const LABEL_FORMAT_A4 = 'a4';
    public const LABEL_FORMAT_THERMAL = 'thermal';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['dhl_shipment:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class)]
    #[ORM\JoinColumn(name: 'order_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Groups(['dhl_shipment:read'])]
    private ?Order $order = null;

    #[ORM\Column(length: 20)]
    #[Groups(['dhl_shipment:read'])]
    private string $status = self::STATUS_BOOKED;

    /** DHL Express waybill number — also written to Order.trackingNumber. */
    #[ORM\Column(length: 20)]
    #[Groups(['dhl_shipment:read'])]
    private ?string $awb = null;

    /** MyDHL global product code, e.g. U/P Express Worldwide, T/Y 12:00, W/H Economy Select. */
    #[ORM\Column(length: 5)]
    #[Groups(['dhl_shipment:read'])]
    private ?string $productCode = null;

    #[ORM\Column(length: 100, nullable: true)]
    #[Groups(['dhl_shipment:read'])]
    private ?string $productName = null;

    #[ORM\Column(type: 'datetime')]
    #[Groups(['dhl_shipment:read'])]
    private ?\DateTimeInterface $plannedShippingAt = null;

    /** Dutiable (non-EU) shipment sent with a customs declaration. */
    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['dhl_shipment:read'])]
    #[SerializedName('isCustomsDeclarable')]
    private bool $isCustomsDeclarable = false;

    /**
     * Pieces as booked: [{weight, length, width, height, trackingNumber}], kg/cm.
     */
    #[ORM\Column(type: 'json')]
    #[Groups(['dhl_shipment:read'])]
    private array $packages = [];

    #[ORM\Column(type: 'decimal', precision: 10, scale: 3)]
    #[Groups(['dhl_shipment:read'])]
    private ?string $totalWeight = null;

    /** Shipper address/contact exactly as sent to DHL. */
    #[ORM\Column(type: 'json')]
    #[Groups(['dhl_shipment:read'])]
    private array $shipper = [];

    /** Receiver address/contact exactly as sent to DHL. */
    #[ORM\Column(type: 'json')]
    #[Groups(['dhl_shipment:read'])]
    private array $receiver = [];

    /**
     * Stored documents: typeCode ("label", "invoice", ...) => file name in DhlLabelStorage.
     * Not serialized — the API exposes only the type list (see getDocumentTypes).
     */
    #[ORM\Column(type: 'json')]
    private array $documents = [];

    #[ORM\Column(length: 20, options: ['default' => self::LABEL_FORMAT_A4])]
    #[Groups(['dhl_shipment:read'])]
    private string $labelFormat = self::LABEL_FORMAT_A4;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['dhl_shipment:read'])]
    private ?string $price = null;

    #[ORM\Column(length: 3, nullable: true)]
    #[Groups(['dhl_shipment:read'])]
    private ?string $currency = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(['dhl_shipment:read'])]
    private ?\DateTimeInterface $estimatedDeliveryAt = null;

    // Courier pickup (optional — booked with the shipment or separately)
    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['dhl_shipment:read'])]
    private ?string $pickupConfirmationNumber = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(['dhl_shipment:read'])]
    private ?\DateTimeInterface $pickupReadyAt = null;

    /** Latest time the courier can come, local "HH:MM". */
    #[ORM\Column(length: 5, nullable: true)]
    #[Groups(['dhl_shipment:read'])]
    private ?string $pickupCloseTime = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(['dhl_shipment:read'])]
    private ?\DateTimeInterface $pickupCancelledAt = null;

    /** Last pickup booking error from DHL (shipment itself is still valid). */
    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['dhl_shipment:read'])]
    private ?string $pickupError = null;

    /** Non-fatal warnings DHL returned with the booking. */
    #[ORM\Column(type: 'json')]
    #[Groups(['dhl_shipment:read'])]
    private array $warnings = [];

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(['dhl_shipment:read'])]
    private ?\DateTimeInterface $cancelledAt = null;

    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'create')]
    #[Groups(['dhl_shipment:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'update')]
    #[Groups(['dhl_shipment:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(Order $order): static
    {
        $this->order = $order;
        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getAwb(): ?string
    {
        return $this->awb;
    }

    public function setAwb(string $awb): static
    {
        $this->awb = $awb;
        return $this;
    }

    public function getProductCode(): ?string
    {
        return $this->productCode;
    }

    public function setProductCode(string $productCode): static
    {
        $this->productCode = $productCode;
        return $this;
    }

    public function getProductName(): ?string
    {
        return $this->productName;
    }

    public function setProductName(?string $productName): static
    {
        $this->productName = $productName;
        return $this;
    }

    public function getPlannedShippingAt(): ?\DateTimeInterface
    {
        return $this->plannedShippingAt;
    }

    public function setPlannedShippingAt(\DateTimeInterface $plannedShippingAt): static
    {
        $this->plannedShippingAt = $plannedShippingAt;
        return $this;
    }

    public function getIsCustomsDeclarable(): bool
    {
        return $this->isCustomsDeclarable;
    }

    public function setIsCustomsDeclarable(bool $isCustomsDeclarable): static
    {
        $this->isCustomsDeclarable = $isCustomsDeclarable;
        return $this;
    }

    public function getPackages(): array
    {
        return $this->packages;
    }

    public function setPackages(array $packages): static
    {
        $this->packages = $packages;
        return $this;
    }

    public function getTotalWeight(): ?string
    {
        return $this->totalWeight;
    }

    public function setTotalWeight(string $totalWeight): static
    {
        $this->totalWeight = $totalWeight;
        return $this;
    }

    public function getShipper(): array
    {
        return $this->shipper;
    }

    public function setShipper(array $shipper): static
    {
        $this->shipper = $shipper;
        return $this;
    }

    public function getReceiver(): array
    {
        return $this->receiver;
    }

    public function setReceiver(array $receiver): static
    {
        $this->receiver = $receiver;
        return $this;
    }

    /** @return array<string, string> typeCode => stored file name */
    public function getDocuments(): array
    {
        return $this->documents;
    }

    public function addDocument(string $typeCode, string $fileName): static
    {
        $this->documents[$typeCode] = $fileName;
        return $this;
    }

    public function getDocumentFile(string $typeCode): ?string
    {
        return $this->documents[$typeCode] ?? null;
    }

    /** @return string[] e.g. ["label", "invoice"] */
    #[Groups(['dhl_shipment:read'])]
    public function getDocumentTypes(): array
    {
        return array_keys($this->documents);
    }

    public function getLabelFormat(): string
    {
        return $this->labelFormat;
    }

    public function setLabelFormat(string $labelFormat): static
    {
        $this->labelFormat = $labelFormat;
        return $this;
    }

    public function getPrice(): ?string
    {
        return $this->price;
    }

    public function setPrice(?string $price): static
    {
        $this->price = $price;
        return $this;
    }

    public function getCurrency(): ?string
    {
        return $this->currency;
    }

    public function setCurrency(?string $currency): static
    {
        $this->currency = $currency;
        return $this;
    }

    public function getEstimatedDeliveryAt(): ?\DateTimeInterface
    {
        return $this->estimatedDeliveryAt;
    }

    public function setEstimatedDeliveryAt(?\DateTimeInterface $estimatedDeliveryAt): static
    {
        $this->estimatedDeliveryAt = $estimatedDeliveryAt;
        return $this;
    }

    public function getPickupConfirmationNumber(): ?string
    {
        return $this->pickupConfirmationNumber;
    }

    public function setPickupConfirmationNumber(?string $pickupConfirmationNumber): static
    {
        $this->pickupConfirmationNumber = $pickupConfirmationNumber;
        return $this;
    }

    public function getPickupReadyAt(): ?\DateTimeInterface
    {
        return $this->pickupReadyAt;
    }

    public function setPickupReadyAt(?\DateTimeInterface $pickupReadyAt): static
    {
        $this->pickupReadyAt = $pickupReadyAt;
        return $this;
    }

    public function getPickupCloseTime(): ?string
    {
        return $this->pickupCloseTime;
    }

    public function setPickupCloseTime(?string $pickupCloseTime): static
    {
        $this->pickupCloseTime = $pickupCloseTime;
        return $this;
    }

    public function getPickupCancelledAt(): ?\DateTimeInterface
    {
        return $this->pickupCancelledAt;
    }

    public function setPickupCancelledAt(?\DateTimeInterface $pickupCancelledAt): static
    {
        $this->pickupCancelledAt = $pickupCancelledAt;
        return $this;
    }

    public function getPickupError(): ?string
    {
        return $this->pickupError;
    }

    public function setPickupError(?string $pickupError): static
    {
        $this->pickupError = $pickupError;
        return $this;
    }

    public function getWarnings(): array
    {
        return $this->warnings;
    }

    public function setWarnings(array $warnings): static
    {
        $this->warnings = $warnings;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getCancelledAt(): ?\DateTimeInterface
    {
        return $this->cancelledAt;
    }

    public function setCancelledAt(?\DateTimeInterface $cancelledAt): static
    {
        $this->cancelledAt = $cancelledAt;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }
}
