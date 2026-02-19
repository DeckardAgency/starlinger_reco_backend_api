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
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['product_link:read'])]
    private ?int $id = null;

    #[ORM\Column(name: 'parent_product_id', type: 'integer', nullable: true)]
    #[Groups(['product_link:read', 'product_link:write'])]
    private ?int $parentProductId = null;

    #[ORM\Column(name: 'child_product_id', type: 'integer', nullable: true)]
    #[Groups(['product_link:read', 'product_link:write'])]
    private ?int $childProductId = null;

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

    public function getId(): ?int
    {
        return $this->id;
    }

    public function setId(int $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function getParentProductId(): ?int
    {
        return $this->parentProductId;
    }

    public function setParentProductId(?int $parentProductId): static
    {
        $this->parentProductId = $parentProductId;
        return $this;
    }

    public function getChildProductId(): ?int
    {
        return $this->childProductId;
    }

    public function setChildProductId(?int $childProductId): static
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
