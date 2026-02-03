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
use App\Repository\DiscountRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['discount:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['discount:read']],
            denormalizationContext: ['groups' => ['discount:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['discount:read']]
        ),
        new Put(
            normalizationContext: ['groups' => ['discount:read']],
            denormalizationContext: ['groups' => ['discount:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['discount:read']],
            denormalizationContext: ['groups' => ['discount:write']]
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['discount:read']],
    denormalizationContext: ['groups' => ['discount:write']]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'partial'
])]
#[ApiFilter(BooleanFilter::class, properties: ['isActive'])]
#[ORM\Entity(repositoryClass: DiscountRepository::class)]
#[ORM\Table]
class Discount
{
    #[ORM\Id]
    #[ORM\Column(type: "uuid", unique: true)]
    #[Groups(['discount:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['discount:read', 'discount:write'])]
    private ?string $name = null;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['discount:read', 'discount:write'])]
    private bool $isActive = true;

    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(['discount:read', 'discount:write'])]
    private ?\DateTimeInterface $dateValidFrom = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(['discount:read', 'discount:write'])]
    private ?\DateTimeInterface $dateValidTo = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['discount:read', 'discount:write'])]
    private ?int $priority = 0;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    #[Groups(['discount:read', 'discount:write'])]
    private ?string $discountPercent = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['discount:read', 'discount:write'])]
    private ?string $rules = null;

    /**
     * Legacy database ID for migration
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['discount:read'])]
    private ?int $legacyId = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['discount:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['discount:read'])]
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

    public function isActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getDateValidFrom(): ?\DateTimeInterface
    {
        return $this->dateValidFrom;
    }

    public function setDateValidFrom(?\DateTimeInterface $dateValidFrom): static
    {
        $this->dateValidFrom = $dateValidFrom;
        return $this;
    }

    public function getDateValidTo(): ?\DateTimeInterface
    {
        return $this->dateValidTo;
    }

    public function setDateValidTo(?\DateTimeInterface $dateValidTo): static
    {
        $this->dateValidTo = $dateValidTo;
        return $this;
    }

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    public function setPriority(?int $priority): static
    {
        $this->priority = $priority;
        return $this;
    }

    public function getDiscountPercent(): ?string
    {
        return $this->discountPercent;
    }

    public function setDiscountPercent(?string $discountPercent): static
    {
        $this->discountPercent = $discountPercent;
        return $this;
    }

    public function getRules(): ?string
    {
        return $this->rules;
    }

    public function setRules(?string $rules): static
    {
        $this->rules = $rules;
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
