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
use App\Repository\TaxTypeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['tax_type:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['tax_type:read']],
            denormalizationContext: ['groups' => ['tax_type:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['tax_type:read']]
        ),
        new Put(
            normalizationContext: ['groups' => ['tax_type:read']],
            denormalizationContext: ['groups' => ['tax_type:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['tax_type:read']],
            denormalizationContext: ['groups' => ['tax_type:write']]
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['tax_type:read']],
    denormalizationContext: ['groups' => ['tax_type:write']]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'partial',
    'remoteCode' => 'exact'
])]
#[ApiFilter(BooleanFilter::class, properties: ['isActive'])]
#[ORM\Entity(repositoryClass: TaxTypeRepository::class)]
#[ORM\Table]
class TaxType
{
    #[ORM\Id]
    #[ORM\Column(type: "uuid", unique: true)]
    #[Groups(['tax_type:read', 'country:read', 'product:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['tax_type:read', 'tax_type:write', 'country:read', 'product:read'])]
    private ?string $name = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[Groups(['tax_type:read', 'tax_type:write', 'country:read', 'product:read'])]
    private ?string $percent = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['tax_type:read', 'tax_type:write'])]
    private ?int $remoteId = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['tax_type:read', 'tax_type:write'])]
    private ?string $remoteCode = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['tax_type:read', 'tax_type:write'])]
    private bool $isActive = true;

    /**
     * Legacy database ID for migration
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['tax_type:read'])]
    private ?int $legacyId = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['tax_type:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['tax_type:read'])]
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

    public function getPercent(): ?string
    {
        return $this->percent;
    }

    public function setPercent(string $percent): static
    {
        $this->percent = $percent;
        return $this;
    }

    public function getRemoteId(): ?int
    {
        return $this->remoteId;
    }

    public function setRemoteId(?int $remoteId): static
    {
        $this->remoteId = $remoteId;
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
