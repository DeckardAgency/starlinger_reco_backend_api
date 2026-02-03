<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\NumericFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Repository\DeliveryPriceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['delivery_price:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['delivery_price:read']],
            denormalizationContext: ['groups' => ['delivery_price:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['delivery_price:read']]
        ),
        new Put(
            normalizationContext: ['groups' => ['delivery_price:read']],
            denormalizationContext: ['groups' => ['delivery_price:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['delivery_price:read']],
            denormalizationContext: ['groups' => ['delivery_price:write']]
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['delivery_price:read']],
    denormalizationContext: ['groups' => ['delivery_price:write']]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'partial',
    'deliveryType.id' => 'exact'
])]
#[ApiFilter(NumericFilter::class, properties: ['dhlZone', 'sizeFrom', 'sizeTo'])]
#[ORM\Entity(repositoryClass: DeliveryPriceRepository::class)]
#[ORM\Table]
class DeliveryPrice
{
    #[ORM\Id]
    #[ORM\Column(type: "uuid", unique: true)]
    #[Groups(['delivery_price:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['delivery_price:read', 'delivery_price:write'])]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: DeliveryType::class, inversedBy: 'prices')]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull]
    #[Groups(['delivery_price:read', 'delivery_price:write'])]
    private ?DeliveryType $deliveryType = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['delivery_price:read', 'delivery_price:write'])]
    private ?int $dhlZone = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['delivery_price:read', 'delivery_price:write'])]
    private ?string $postalCodeFrom = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['delivery_price:read', 'delivery_price:write'])]
    private ?string $postalCodeTo = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['delivery_price:read', 'delivery_price:write'])]
    private ?string $excludePostalCodes = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    #[Groups(['delivery_price:read', 'delivery_price:write'])]
    private ?string $sizeFrom = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    #[Groups(['delivery_price:read', 'delivery_price:write'])]
    private ?string $sizeTo = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2)]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[Groups(['delivery_price:read', 'delivery_price:write'])]
    private ?string $priceBase = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['delivery_price:read', 'delivery_price:write'])]
    private ?int $deliveryDays = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    #[Groups(['delivery_price:read', 'delivery_price:write'])]
    private ?string $stepStartsAt = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 4, nullable: true)]
    #[Groups(['delivery_price:read', 'delivery_price:write'])]
    private ?string $forEveryNextSize = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['delivery_price:read', 'delivery_price:write'])]
    private ?string $priceBaseStep = null;

    /**
     * Legacy database ID for migration
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['delivery_price:read'])]
    private ?int $legacyId = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['delivery_price:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['delivery_price:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getDeliveryType(): ?DeliveryType
    {
        return $this->deliveryType;
    }

    public function setDeliveryType(?DeliveryType $deliveryType): static
    {
        $this->deliveryType = $deliveryType;
        return $this;
    }

    public function getDhlZone(): ?int
    {
        return $this->dhlZone;
    }

    public function setDhlZone(?int $dhlZone): static
    {
        $this->dhlZone = $dhlZone;
        return $this;
    }

    public function getPostalCodeFrom(): ?string
    {
        return $this->postalCodeFrom;
    }

    public function setPostalCodeFrom(?string $postalCodeFrom): static
    {
        $this->postalCodeFrom = $postalCodeFrom;
        return $this;
    }

    public function getPostalCodeTo(): ?string
    {
        return $this->postalCodeTo;
    }

    public function setPostalCodeTo(?string $postalCodeTo): static
    {
        $this->postalCodeTo = $postalCodeTo;
        return $this;
    }

    public function getExcludePostalCodes(): ?string
    {
        return $this->excludePostalCodes;
    }

    public function setExcludePostalCodes(?string $excludePostalCodes): static
    {
        $this->excludePostalCodes = $excludePostalCodes;
        return $this;
    }

    public function getSizeFrom(): ?string
    {
        return $this->sizeFrom;
    }

    public function setSizeFrom(?string $sizeFrom): static
    {
        $this->sizeFrom = $sizeFrom;
        return $this;
    }

    public function getSizeTo(): ?string
    {
        return $this->sizeTo;
    }

    public function setSizeTo(?string $sizeTo): static
    {
        $this->sizeTo = $sizeTo;
        return $this;
    }

    public function getPriceBase(): ?string
    {
        return $this->priceBase;
    }

    public function setPriceBase(string $priceBase): static
    {
        $this->priceBase = $priceBase;
        return $this;
    }

    public function getDeliveryDays(): ?int
    {
        return $this->deliveryDays;
    }

    public function setDeliveryDays(?int $deliveryDays): static
    {
        $this->deliveryDays = $deliveryDays;
        return $this;
    }

    public function getStepStartsAt(): ?string
    {
        return $this->stepStartsAt;
    }

    public function setStepStartsAt(?string $stepStartsAt): static
    {
        $this->stepStartsAt = $stepStartsAt;
        return $this;
    }

    public function getForEveryNextSize(): ?string
    {
        return $this->forEveryNextSize;
    }

    public function setForEveryNextSize(?string $forEveryNextSize): static
    {
        $this->forEveryNextSize = $forEveryNextSize;
        return $this;
    }

    public function getPriceBaseStep(): ?string
    {
        return $this->priceBaseStep;
    }

    public function setPriceBaseStep(?string $priceBaseStep): static
    {
        $this->priceBaseStep = $priceBaseStep;
        return $this;
    }

    public function getLegacyId(): ?int
    {
        return $this->legacyId;
    }

    public function setLegacyId(?int $legacyId): static
    {
        $this->legacyId = $legacyId;
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
}
