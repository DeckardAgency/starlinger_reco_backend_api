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
use App\Repository\TaxTypeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\SerializedName;

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
])]
#[ApiFilter(BooleanFilter::class, properties: ['isActive'])]
#[ApiFilter(OrderFilter::class, properties: ['name', 'percent'])]
#[ORM\Entity(repositoryClass: TaxTypeRepository::class)]
#[ORM\Table]
class TaxType
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['tax_type:read', 'country:read', 'product:read', 'address:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['tax_type:read', 'tax_type:write', 'country:read', 'product:read', 'address:read'])]
    private ?string $name = null;

    #[ORM\Column(type: 'decimal', precision: 5, scale: 2)]
    #[Assert\NotNull]
    #[Assert\PositiveOrZero]
    #[Groups(['tax_type:read', 'tax_type:write', 'country:read', 'product:read', 'address:read'])]
    private ?string $percent = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['tax_type:read', 'tax_type:write'])]
    private ?int $remoteId = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['tax_type:read', 'tax_type:write'])]
    #[SerializedName('isActive')]
    private bool $isActive = true;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['tax_type:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['tax_type:read'])]
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
