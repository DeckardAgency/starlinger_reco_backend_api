<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Delete;
use App\Repository\OrderItemRepository;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Gedmo\Mapping\Annotation as Gedmo;
use ApiPlatform\Metadata\ApiProperty;

#[ORM\Entity(repositoryClass: OrderItemRepository::class)]
#[ApiResource(
    operations: [
        new Get(normalizationContext: ['groups' => ['order_item:read', 'product:read']]),
        new GetCollection(
            paginationItemsPerPage: 30,
            paginationClientItemsPerPage: true,
            normalizationContext: ['groups' => ['order_item:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['order_item:read']],
            denormalizationContext: ['groups' => ['order_item:write']]
        ),
        new Put(
            normalizationContext: ['groups' => ['order_item:read']],
            denormalizationContext: ['groups' => ['order_item:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['order_item:read']],
            denormalizationContext: ['groups' => ['order_item:write']]
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['order_item:read']],
    denormalizationContext: ['groups' => ['order_item:write']]
)]
class OrderItem
{
    public const INFO_STATUS_NONE = 'none';
    public const INFO_STATUS_CLEAR = 'clear';
    public const INFO_STATUS_PENDING_INFO = 'pending_info';
    public const INFO_STATUS_INFO_PROVIDED = 'info_provided';
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['order_item:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'items')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['order_item:read', 'order_item:write'])]
    #[ApiProperty(readableLink: false, writableLink: false)]
    private ?Order $orderRef = null;

    #[ORM\ManyToOne(targetEntity: Product::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['order_item:read', 'order_item:write', 'order:read', 'order:write'])]
    #[ApiProperty(readableLink: true, writableLink: false)]
    private ?Product $product = null;

    /**
     * The client this line is ordered on behalf of, when an agent places a
     * mixed-client order. Null for ordinary lines. Validated/stripped in
     * OrderPriceProcessor via ClientAgentAuthorization. Writable via the parent
     * order:write group (nested writes) as well as its own.
     */
    #[ORM\ManyToOne(targetEntity: Client::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['order_item:read', 'order_item:write', 'order:read', 'order:write'])]
    private ?Client $onBehalfOfClient = null;

    #[ORM\Column(type: "integer")]
    #[Groups(['order_item:read', 'order_item:write', 'order:read', 'order:write'])]
    private int $quantity = 1;

    #[ORM\Column(type: "float")]
    #[Groups(['order_item:read', 'order:read'])]
    private float $unitPrice = 0;

    #[ORM\Column(type: "float")]
    #[Groups(['order_item:read', 'order:read'])]
    private float $subtotal = 0;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['order_item:read'])]
    private ?DateTimeInterface $createdAt;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['order_item:read'])]
    private ?DateTimeInterface $updatedAt;

    #[ORM\Column(type: "boolean")]
    #[Groups(['order_item:read'])]
    private bool $isCustomPrice = false;

    #[ORM\Column(type: "float", nullable: true)]
    #[Groups(['order_item:read', 'order:read'])]
    private ?float $originalUnitPrice = null;

    #[ORM\Column(type: "float", options: ['default' => 0])]
    #[Groups(['order_item:read', 'order:read'])]
    private float $discountPercent = 0;

    #[ORM\Column(type: "float", options: ['default' => 0])]
    #[Groups(['order_item:read', 'order:read'])]
    private float $taxPercent = 0;

    #[ORM\Column(type: "float", options: ['default' => 0])]
    #[Groups(['order_item:read', 'order:read'])]
    private float $taxAmount = 0;

    #[ORM\Column(length: 20, options: ['default' => 'none'])]
    #[Groups(['order_item:read', 'order:read'])]
    private string $infoStatus = self::INFO_STATUS_NONE;

    /**
     * @var Collection<int, OrderInfoRequest>
     */
    #[ORM\OneToMany(targetEntity: OrderInfoRequest::class, mappedBy: 'orderItem', cascade: ['persist', 'remove'])]
    #[ORM\OrderBy(['createdAt' => 'DESC'])]
    #[Groups(['order_item:read'])]
    private Collection $infoRequests;

    public function __construct()
    {
        $this->infoRequests = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;

        return $this;
    }

    public function getOrderRef(): ?Order
    {
        return $this->orderRef;
    }

    public function setOrderRef(?Order $orderRef): static
    {
        $this->orderRef = $orderRef;
        return $this;
    }

    public function getProduct(): ?Product
    {
        return $this->product;
    }

    public function setProduct(?Product $product): static
    {
        $this->product = $product;
        return $this;
    }

    public function getQuantity(): int
    {
        return $this->quantity;
    }

    public function setQuantity(int $quantity): static
    {
        $this->quantity = $quantity;
        $this->calculateSubtotal();
        return $this;
    }

    public function getUnitPrice(): float
    {
        return $this->unitPrice;
    }

    public function setUnitPrice(float $unitPrice): static
    {
        $this->unitPrice = $unitPrice;
        $this->calculateSubtotal();
        return $this;
    }

    public function getSubtotal(): float
    {
        return $this->subtotal;
    }

    public function setSubtotal(float $subtotal): static
    {
        $this->subtotal = $subtotal;
        return $this;
    }

    private function calculateSubtotal(): void
    {
        $this->subtotal = $this->unitPrice * $this->quantity;
        $this->taxAmount = round($this->subtotal * ($this->taxPercent / 100), 2);
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    public function isCustomPrice(): bool
    {
        return $this->isCustomPrice;
    }

    public function setIsCustomPrice(bool $isCustomPrice): static
    {
        $this->isCustomPrice = $isCustomPrice;
        return $this;
    }

    public function getOriginalUnitPrice(): ?float
    {
        return $this->originalUnitPrice;
    }

    public function setOriginalUnitPrice(?float $originalUnitPrice): static
    {
        $this->originalUnitPrice = $originalUnitPrice;
        return $this;
    }

    public function getDiscountPercent(): float
    {
        return $this->discountPercent;
    }

    public function setDiscountPercent(float $discountPercent): static
    {
        $this->discountPercent = $discountPercent;
        return $this;
    }

    public function getTaxPercent(): float
    {
        return $this->taxPercent;
    }

    public function setTaxPercent(float $taxPercent): static
    {
        $this->taxPercent = $taxPercent;
        $this->calculateSubtotal();
        return $this;
    }

    public function getTaxAmount(): float
    {
        return $this->taxAmount;
    }

    public function setTaxAmount(float $taxAmount): static
    {
        $this->taxAmount = $taxAmount;
        return $this;
    }

    public function getInfoStatus(): string
    {
        return $this->infoStatus;
    }

    public function setInfoStatus(string $infoStatus): static
    {
        $this->infoStatus = $infoStatus;
        return $this;
    }

    /**
     * @return Collection<int, OrderInfoRequest>
     */
    public function getInfoRequests(): Collection
    {
        return $this->infoRequests;
    }

    public function addInfoRequest(OrderInfoRequest $infoRequest): static
    {
        if (!$this->infoRequests->contains($infoRequest)) {
            $this->infoRequests->add($infoRequest);
            $infoRequest->setOrderItem($this);
        }
        return $this;
    }

    public function removeInfoRequest(OrderInfoRequest $infoRequest): static
    {
        if ($this->infoRequests->removeElement($infoRequest)) {
            if ($infoRequest->getOrderItem() === $this) {
                $infoRequest->setOrderItem(null);
            }
        }
        return $this;
    }

    public function getOnBehalfOfClient(): ?Client
    {
        return $this->onBehalfOfClient;
    }

    public function setOnBehalfOfClient(?Client $onBehalfOfClient): static
    {
        $this->onBehalfOfClient = $onBehalfOfClient;
        return $this;
    }
}
