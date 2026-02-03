<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Repository\CountryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['country:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['country:read']],
            denormalizationContext: ['groups' => ['country:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['country:read']]
        ),
        new Put(
            normalizationContext: ['groups' => ['country:read']],
            denormalizationContext: ['groups' => ['country:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['country:read']],
            denormalizationContext: ['groups' => ['country:write']]
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['country:read']],
    denormalizationContext: ['groups' => ['country:write']]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'partial',
    'code' => 'exact',
    'iso31661Alpha3Code' => 'exact'
])]
#[ApiFilter(BooleanFilter::class, properties: ['europeanUnion', 'isActive'])]
#[ORM\Entity(repositoryClass: CountryRepository::class)]
#[ORM\Table]
#[ORM\Index(name: "idx_country_code", columns: ["code"])]
class Country
{
    #[ORM\Id]
    #[ORM\Column(type: "uuid", unique: true)]
    #[Groups(['country:read', 'delivery_price:read', 'client:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['country:read', 'country:write', 'delivery_price:read', 'client:read'])]
    private ?string $name = null;

    #[ORM\Column(length: 2, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(exactly: 2)]
    #[Groups(['country:read', 'country:write', 'delivery_price:read', 'client:read'])]
    private ?string $code = null;

    #[ORM\Column(length: 3, nullable: true)]
    #[Assert\Length(exactly: 3)]
    #[Groups(['country:read', 'country:write'])]
    private ?string $iso31661Alpha3Code = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['country:read', 'country:write'])]
    private bool $europeanUnion = false;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2, nullable: true)]
    #[Groups(['country:read', 'country:write'])]
    private ?string $defaultTaxPercent = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['country:read', 'country:write'])]
    private ?int $dhlZone = null;

    #[ORM\ManyToOne(targetEntity: TaxType::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['country:read', 'country:write'])]
    private ?TaxType $taxType = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['country:read', 'country:write'])]
    private bool $isActive = true;

    /**
     * Legacy database ID for migration
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['country:read'])]
    private ?int $legacyId = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['country:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['country:read'])]
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

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = strtoupper($code);
        return $this;
    }

    public function getIso31661Alpha3Code(): ?string
    {
        return $this->iso31661Alpha3Code;
    }

    public function setIso31661Alpha3Code(?string $iso31661Alpha3Code): static
    {
        $this->iso31661Alpha3Code = $iso31661Alpha3Code ? strtoupper($iso31661Alpha3Code) : null;
        return $this;
    }

    public function isEuropeanUnion(): bool
    {
        return $this->europeanUnion;
    }

    public function setEuropeanUnion(bool $europeanUnion): static
    {
        $this->europeanUnion = $europeanUnion;
        return $this;
    }

    public function getDefaultTaxPercent(): ?string
    {
        return $this->defaultTaxPercent;
    }

    public function setDefaultTaxPercent(?string $defaultTaxPercent): static
    {
        $this->defaultTaxPercent = $defaultTaxPercent;
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

    public function getTaxType(): ?TaxType
    {
        return $this->taxType;
    }

    public function setTaxType(?TaxType $taxType): static
    {
        $this->taxType = $taxType;
        return $this;
    }

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
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
