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
use App\Repository\PackagingPriceRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['packaging_price:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['packaging_price:read']],
            denormalizationContext: ['groups' => ['packaging_price:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['packaging_price:read']]
        ),
        new Put(
            normalizationContext: ['groups' => ['packaging_price:read']],
            denormalizationContext: ['groups' => ['packaging_price:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['packaging_price:read']],
            denormalizationContext: ['groups' => ['packaging_price:write']]
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['packaging_price:read']],
    denormalizationContext: ['groups' => ['packaging_price:write']]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'partial'
])]
#[ApiFilter(NumericFilter::class, properties: ['sizeFrom', 'sizeTo', 'priceBase'])]
#[ORM\Entity(repositoryClass: PackagingPriceRepository::class)]
#[ORM\Table]
class PackagingPrice
{
    #[ORM\Id]
    #[ORM\Column(type: "uuid", unique: true)]
    #[Groups(['packaging_price:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['packaging_price:read', 'packaging_price:write'])]
    private ?string $name = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    #[Groups(['packaging_price:read', 'packaging_price:write'])]
    private ?string $sizeFrom = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    #[Groups(['packaging_price:read', 'packaging_price:write'])]
    private ?string $sizeTo = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4)]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[Groups(['packaging_price:read', 'packaging_price:write'])]
    private ?string $priceBase = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['packaging_price:read'])]
    private ?int $legacyId = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['packaging_price:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['packaging_price:read'])]
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
