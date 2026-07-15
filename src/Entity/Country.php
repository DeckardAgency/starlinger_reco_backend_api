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
use App\Repository\CountryRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\SerializedName;

use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['country:read']],
            // Effectively-static master data: let the browser cache it for 1h.
            // max_age only (private cache) — never shared_max_age, since responses
            // are credentialed and must not be cached by a shared proxy/CDN.
            cacheHeaders: ['max_age' => 3600]
        ),
        new Post(
            normalizationContext: ['groups' => ['country:read']],
            denormalizationContext: ['groups' => ['country:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['country:read']],
            cacheHeaders: ['max_age' => 3600]
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
])]
#[ApiFilter(BooleanFilter::class, properties: ['europeanUnion', 'isActive'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'code'])]
#[ORM\Entity(repositoryClass: CountryRepository::class)]
#[ORM\Table]
#[ORM\Index(name: "idx_country_code", columns: ["code"])]
class Country
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['country:read', 'delivery_price:read', 'client:read', 'address:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['country:read', 'country:write', 'delivery_price:read', 'client:read', 'address:read'])]
    private ?string $name = null;

    #[ORM\Column(length: 2, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(exactly: 2)]
    #[Groups(['country:read', 'country:write', 'delivery_price:read', 'client:read', 'address:read'])]
    private ?string $code = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['country:read', 'country:write'])]
    private bool $europeanUnion = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['country:read', 'country:write'])]
    private ?int $dhlZone = null;

    #[ORM\ManyToOne(targetEntity: TaxType::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Assert\NotNull(message: 'A country must have a tax type assigned.')]
    #[Groups(['country:read', 'country:write', 'address:read'])]
    private ?TaxType $taxType = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['country:read', 'country:write'])]
    #[SerializedName('isActive')]
    private bool $isActive = true;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['country:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['country:read'])]
    private ?\DateTimeInterface $updatedAt = null;

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

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = strtoupper($code);
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

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
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
