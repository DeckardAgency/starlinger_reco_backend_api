<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Metadata\ApiProperty;
use App\Repository\DeliveryTypeRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\SerializedName;

use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['delivery_type:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['delivery_type:read']],
            denormalizationContext: ['groups' => ['delivery_type:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['delivery_type:read']]
        ),
        new Put(
            normalizationContext: ['groups' => ['delivery_type:read']],
            denormalizationContext: ['groups' => ['delivery_type:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['delivery_type:read']],
            denormalizationContext: ['groups' => ['delivery_type:write']]
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['delivery_type:read']],
    denormalizationContext: ['groups' => ['delivery_type:write']]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'partial',
    'remoteCode' => 'exact'
])]
#[ApiFilter(BooleanFilter::class, properties: ['isActive', 'isDelivery', 'allowRecurringPayment'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'isActive'])]
#[ORM\Entity(repositoryClass: DeliveryTypeRepository::class)]
#[ORM\Table]
class DeliveryType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['delivery_type:read', 'delivery_price:read', 'order:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['delivery_type:read', 'delivery_type:write', 'delivery_price:read', 'order:read'])]
    private ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['delivery_type:read', 'delivery_type:write'])]
    private ?string $shortDescription = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['delivery_type:read', 'delivery_type:write'])]
    private ?string $remoteCode = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['delivery_type:read', 'delivery_type:write'])]
    private ?string $remoteId = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['delivery_type:read', 'delivery_type:write'])]
    private ?string $color = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['delivery_type:read', 'delivery_type:write'])]
    #[SerializedName('isDelivery')]
    private bool $isDelivery = true;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['delivery_type:read', 'delivery_type:write'])]
    private ?string $maxWeight = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 4, nullable: true)]
    #[Groups(['delivery_type:read', 'delivery_type:write'])]
    private ?string $grossFactor = null;

    #[ORM\ManyToOne(targetEntity: Warehouse::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['delivery_type:read', 'delivery_type:write'])]
    private ?Warehouse $warehouse = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['delivery_type:read', 'delivery_type:write'])]
    #[SerializedName('hideIfNotApplicable')]
    private bool $hideIfNotApplicable = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['delivery_type:read', 'delivery_type:write'])]
    #[SerializedName('allowRecurringPayment')]
    private bool $allowRecurringPayment = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['delivery_type:read', 'delivery_type:write'])]
    #[SerializedName('useAsDefault')]
    private bool $useAsDefault = false;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['delivery_type:read', 'delivery_type:write'])]
    #[SerializedName('isActive')]
    private bool $isActive = true;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['delivery_type:read', 'delivery_type:write'])]
    #[SerializedName('readyForShop')]
    private bool $readyForShop = false;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['delivery_type:read', 'delivery_type:write'])]
    private int $sortOrder = 0;

    /**
     * @var Collection<int, DeliveryPrice>
     */
    #[ORM\OneToMany(targetEntity: DeliveryPrice::class, mappedBy: 'deliveryType', cascade: ['persist', 'remove'], orphanRemoval: true)]
    private Collection $prices;

    #[ORM\OneToMany(targetEntity: MediaItem::class, mappedBy: 'deliveryTypeDocument', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['delivery_type:read', 'delivery_type:write'])]
    #[ApiProperty(writableLink: true)]
    private Collection $documents;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['delivery_type:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['delivery_type:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->prices = new ArrayCollection();
        $this->documents = new ArrayCollection();
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getShortDescription(): ?string
    {
        return $this->shortDescription;
    }

    public function setShortDescription(?string $shortDescription): static
    {
        $this->shortDescription = $shortDescription;
        return $this;
    }

    public function getRemoteCode(): ?string
    {
        return $this->remoteCode;
    }

    public function setRemoteCode(?string $remoteCode): static
    {
        $this->remoteCode = $remoteCode;
        return $this;
    }

    public function getRemoteId(): ?string
    {
        return $this->remoteId;
    }

    public function setRemoteId(?string $remoteId): static
    {
        $this->remoteId = $remoteId;
        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;
        return $this;
    }

    public function getIsDelivery(): bool
    {
        return $this->isDelivery;
    }

    public function setIsDelivery(bool $isDelivery): static
    {
        $this->isDelivery = $isDelivery;
        return $this;
    }

    public function getMaxWeight(): ?string
    {
        return $this->maxWeight;
    }

    public function setMaxWeight(?string $maxWeight): static
    {
        $this->maxWeight = $maxWeight;
        return $this;
    }

    public function getGrossFactor(): ?string
    {
        return $this->grossFactor;
    }

    public function setGrossFactor(?string $grossFactor): static
    {
        $this->grossFactor = $grossFactor;
        return $this;
    }

    public function getWarehouse(): ?Warehouse
    {
        return $this->warehouse;
    }

    public function setWarehouse(?Warehouse $warehouse): static
    {
        $this->warehouse = $warehouse;
        return $this;
    }

    public function getHideIfNotApplicable(): bool
    {
        return $this->hideIfNotApplicable;
    }

    public function setHideIfNotApplicable(bool $hideIfNotApplicable): static
    {
        $this->hideIfNotApplicable = $hideIfNotApplicable;
        return $this;
    }

    public function getAllowRecurringPayment(): bool
    {
        return $this->allowRecurringPayment;
    }

    public function setAllowRecurringPayment(bool $allowRecurringPayment): static
    {
        $this->allowRecurringPayment = $allowRecurringPayment;
        return $this;
    }

    public function getUseAsDefault(): bool
    {
        return $this->useAsDefault;
    }

    public function setUseAsDefault(bool $useAsDefault): static
    {
        $this->useAsDefault = $useAsDefault;
        return $this;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getReadyForShop(): bool
    {
        return $this->readyForShop;
    }

    public function setReadyForShop(bool $readyForShop): static
    {
        $this->readyForShop = $readyForShop;
        return $this;
    }

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    /**
     * @return Collection<int, DeliveryPrice>
     */
    public function getPrices(): Collection
    {
        return $this->prices;
    }

    public function addPrice(DeliveryPrice $price): static
    {
        if (!$this->prices->contains($price)) {
            $this->prices->add($price);
            $price->setDeliveryType($this);
        }
        return $this;
    }

    public function removePrice(DeliveryPrice $price): static
    {
        if ($this->prices->removeElement($price)) {
            if ($price->getDeliveryType() === $this) {
                $price->setDeliveryType(null);
            }
        }
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(\DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(\DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @return Collection<int, MediaItem>
     */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(MediaItem $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setDeliveryTypeDocument($this);
        }

        return $this;
    }

    public function removeDocument(MediaItem $document): static
    {
        if ($this->documents->removeElement($document)) {
            if ($document->getDeliveryTypeDocument() === $this) {
                $document->setDeliveryTypeDocument(null);
            }
        }

        return $this;
    }
}
