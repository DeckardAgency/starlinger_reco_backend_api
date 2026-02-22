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
use App\Repository\AccountGroupRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\SerializedName;

use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['account_group:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['account_group:read']],
            denormalizationContext: ['groups' => ['account_group:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['account_group:read']]
        ),
        new Put(
            normalizationContext: ['groups' => ['account_group:read']],
            denormalizationContext: ['groups' => ['account_group:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['account_group:read']],
            denormalizationContext: ['groups' => ['account_group:write']]
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['account_group:read']],
    denormalizationContext: ['groups' => ['account_group:write']]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'partial'
])]
#[ApiFilter(BooleanFilter::class, properties: ['isActive'])]
#[ApiFilter(OrderFilter::class, properties: ['name'])]
#[ORM\Entity(repositoryClass: AccountGroupRepository::class)]
#[ORM\Table(name: 'account_group')]
class AccountGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['account_group:read', 'client:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['account_group:read', 'account_group:write', 'client:read'])]
    private ?string $name = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['account_group:read', 'account_group:write'])]
    #[SerializedName('isActive')]
    private bool $isActive = true;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['account_group:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['account_group:read'])]
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
