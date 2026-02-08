<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
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
use App\Repository\FuelSurchargeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Gedmo\Mapping\Annotation as Gedmo;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['fuel_surcharge:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['fuel_surcharge:read']],
            denormalizationContext: ['groups' => ['fuel_surcharge:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['fuel_surcharge:read']]
        ),
        new Put(
            normalizationContext: ['groups' => ['fuel_surcharge:read']],
            denormalizationContext: ['groups' => ['fuel_surcharge:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['fuel_surcharge:read']],
            denormalizationContext: ['groups' => ['fuel_surcharge:write']]
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['fuel_surcharge:read']],
    denormalizationContext: ['groups' => ['fuel_surcharge:write']]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'partial',
    'deliveryType.id' => 'exact'
])]
#[ApiFilter(DateFilter::class, properties: ['date'])]
#[ApiFilter(NumericFilter::class, properties: ['fuelSurcharge'])]
#[ORM\Entity(repositoryClass: FuelSurchargeRepository::class)]
#[ORM\Table]
class FuelSurcharge
{
    #[ORM\Id]
    #[ORM\Column(type: "uuid", unique: true)]
    #[Groups(['fuel_surcharge:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['fuel_surcharge:read', 'fuel_surcharge:write'])]
    private ?string $name = null;

    #[ORM\ManyToOne(targetEntity: DeliveryType::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['fuel_surcharge:read', 'fuel_surcharge:write'])]
    private ?DeliveryType $deliveryType = null;

    #[ORM\Column(type: 'date', nullable: true)]
    #[Groups(['fuel_surcharge:read', 'fuel_surcharge:write'])]
    private ?\DateTimeInterface $date = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    #[Groups(['fuel_surcharge:read', 'fuel_surcharge:write'])]
    private ?string $fuelSurcharge = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    #[Groups(['fuel_surcharge:read', 'fuel_surcharge:write'])]
    private ?string $sizeFrom = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    #[Groups(['fuel_surcharge:read', 'fuel_surcharge:write'])]
    private ?string $sizeTo = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    #[Groups(['fuel_surcharge:read', 'fuel_surcharge:write'])]
    private ?string $priceBase = null;

    /**
     * Legacy database ID for migration
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['fuel_surcharge:read'])]
    private ?int $legacyId = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['fuel_surcharge:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['fuel_surcharge:read'])]
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

    public function getDate(): ?\DateTimeInterface
    {
        return $this->date;
    }

    public function setDate(?\DateTimeInterface $date): static
    {
        $this->date = $date;
        return $this;
    }

    public function getFuelSurcharge(): ?string
    {
        return $this->fuelSurcharge;
    }

    public function setFuelSurcharge(?string $fuelSurcharge): static
    {
        $this->fuelSurcharge = $fuelSurcharge;
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

    public function setPriceBase(?string $priceBase): static
    {
        $this->priceBase = $priceBase;
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
