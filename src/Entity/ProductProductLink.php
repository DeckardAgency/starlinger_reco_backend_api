<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Repository\ProductProductLinkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Gedmo\Mapping\Annotation as Gedmo;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['product_link:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['product_link:read']],
            denormalizationContext: ['groups' => ['product_link:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['product_link:read']]
        ),
        new Put(
            normalizationContext: ['groups' => ['product_link:read']],
            denormalizationContext: ['groups' => ['product_link:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['product_link:read']],
            denormalizationContext: ['groups' => ['product_link:write']]
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['product_link:read']],
    denormalizationContext: ['groups' => ['product_link:write']]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'parentProductId' => 'exact',
    'childProductId' => 'exact'
])]
#[ORM\Entity(repositoryClass: ProductProductLinkRepository::class)]
#[ORM\Table(name: 'product_product_link')]
class ProductProductLink
{
    #[ORM\Id]
    #[ORM\Column(type: "uuid", unique: true)]
    #[Groups(['product_link:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(name: 'parent_product_id', type: 'string', length: 36)]
    #[Groups(['product_link:read', 'product_link:write'])]
    private ?string $parentProductId = null;

    #[ORM\Column(name: 'child_product_id', type: 'string', length: 36)]
    #[Groups(['product_link:read', 'product_link:write'])]
    private ?string $childProductId = null;

    #[ORM\Column(name: 'relation_type_id', type: 'integer', nullable: true, options: ['unsigned' => true])]
    #[Groups(['product_link:read', 'product_link:write'])]
    private ?int $relationTypeId = null;

    #[ORM\Column(type: 'integer', nullable: true, options: ['unsigned' => true])]
    #[Groups(['product_link:read', 'product_link:write'])]
    private ?int $ord = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['product_link:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['product_link:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getParentProductId(): ?string
    {
        return $this->parentProductId;
    }

    public function setParentProductId(?string $parentProductId): static
    {
        $this->parentProductId = $parentProductId;
        return $this;
    }

    public function getChildProductId(): ?string
    {
        return $this->childProductId;
    }

    public function setChildProductId(?string $childProductId): static
    {
        $this->childProductId = $childProductId;
        return $this;
    }

    public function getRelationTypeId(): ?int
    {
        return $this->relationTypeId;
    }

    public function setRelationTypeId(?int $relationTypeId): static
    {
        $this->relationTypeId = $relationTypeId;
        return $this;
    }

    public function getOrd(): ?int
    {
        return $this->ord;
    }

    public function setOrd(?int $ord): static
    {
        $this->ord = $ord;
        return $this;
    }

    public function getCreatedAt(): ?\DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): ?\DateTimeInterface
    {
        return $this->updatedAt;
    }
}
