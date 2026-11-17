<?php

namespace App\Entity;

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
use App\Enum\ProductLinkRelationType;
use App\Repository\ProductProductLinkRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Gedmo\Mapping\Annotation as Gedmo;

/**
 * A directed link from a parent product to a child product, of one kind
 * (relationType: "alternative" | "replacement"). One link per (parent, child).
 */
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
    denormalizationContext: ['groups' => ['product_link:write']],
    // An unknown relationType value becomes a 422 violation on that field (like a
    // missing one) instead of a bare 400 from the serializer.
    collectDenormalizationErrors: true
)]
#[ApiFilter(SearchFilter::class, properties: [
    'parentProductId' => 'exact',
    'childProductId' => 'exact',
    'relationType' => 'exact'
])]
#[ApiFilter(OrderFilter::class, properties: ['id', 'ord'])]
#[ORM\Entity(repositoryClass: ProductProductLinkRepository::class)]
#[ORM\Table(name: 'product_product_link')]
#[ORM\Index(name: 'idx_ppl_parent', columns: ['parent_product_id'])]
#[ORM\Index(name: 'idx_ppl_child', columns: ['child_product_id'])]
#[ORM\UniqueConstraint(name: 'uniq_ppl_parent_child', columns: ['parent_product_id', 'child_product_id'])]
#[UniqueEntity(
    fields: ['parentProductId', 'childProductId'],
    message: 'This product is already linked to the parent product.'
)]
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

    /**
     * The kind of link: "alternative" (an equivalent product) or "replacement"
     * (the successor of a discontinued product). Required.
     */
    // No PHP default on purpose: a POST without relationType must fail NotNull (422).
    // The DB default 'alternative' only covers rows that predate the column.
    #[ORM\Column(name: 'relation_type', length: 20, enumType: ProductLinkRelationType::class, options: ['default' => 'alternative'])]
    #[Assert\NotNull(message: 'The relation type is required: "alternative" or "replacement".')]
    #[Groups(['product_link:read', 'product_link:write'])]
    private ?ProductLinkRelationType $relationType = null;

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

    #[Assert\Callback]
    public function validateNotLinkedToItself(ExecutionContextInterface $context): void
    {
        if ($this->parentProductId !== null && $this->parentProductId === $this->childProductId) {
            $context->buildViolation('A product cannot be linked to itself.')
                ->atPath('childProductId')
                ->addViolation();
        }
    }

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

    public function getRelationType(): ?ProductLinkRelationType
    {
        return $this->relationType;
    }

    public function setRelationType(?ProductLinkRelationType $relationType): static
    {
        $this->relationType = $relationType;
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
