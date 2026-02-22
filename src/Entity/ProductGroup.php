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
use ApiPlatform\Metadata\ApiProperty;
use App\Repository\ProductGroupRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\SerializedName;

use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['product_group:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['product_group:read']],
            denormalizationContext: ['groups' => ['product_group:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['product_group:read', 'product_group:read:details']]
        ),
        new Put(
            normalizationContext: ['groups' => ['product_group:read']],
            denormalizationContext: ['groups' => ['product_group:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['product_group:read']],
            denormalizationContext: ['groups' => ['product_group:write']]
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['product_group:read']],
    denormalizationContext: ['groups' => ['product_group:write']]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'partial',
    'slug' => 'exact',
    'productGroupCode' => 'exact'
])]
#[ApiFilter(BooleanFilter::class, properties: ['isActive', 'showOnHomepage'])]
#[ApiFilter(OrderFilter::class, properties: ['id', 'name', 'productGroupCode', 'isActive', 'showOnHomepage'])]
#[ORM\Entity(repositoryClass: ProductGroupRepository::class)]
#[ORM\Table]
#[ORM\Index(name: "idx_product_group_slug", columns: ["slug"])]
class ProductGroup
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['product_group:read', 'product:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['product_group:read', 'product_group:write', 'product:read'])]
    private ?string $name = null;

    #[ORM\Column(length: 255, unique: true)]
    #[Gedmo\Slug(fields: ["name"])]
    #[Groups(['product_group:read', 'product:read'])]
    private ?string $slug = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['product_group:read', 'product_group:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['product_group:read', 'product_group:write'])]
    private ?string $productGroupCode = null;

    #[ORM\ManyToOne(targetEntity: ProductGroup::class, inversedBy: 'children')]
    #[ORM\JoinColumn(nullable: true, onDelete: 'SET NULL')]
    #[Groups(['product_group:read', 'product_group:write'])]
    #[ApiProperty(readableLink: false, writableLink: true)]
    private ?ProductGroup $parent = null;

    /**
     * @var Collection<int, ProductGroup>
     */
    #[ORM\OneToMany(targetEntity: ProductGroup::class, mappedBy: 'parent')]
    #[Groups(['product_group:read:details'])]
    private Collection $children;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['product_group:read', 'product_group:write'])]
    private int $level = 0;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['product_group:read', 'product_group:write'])]
    private int $totalProducts = 0;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['product_group:read', 'product_group:write'])]
    private bool $showOnHomepage = false;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['product_group:read', 'product_group:write'])]
    #[SerializedName('isActive')]
    private bool $isActive = true;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['product_group:read', 'product_group:write'])]
    private int $sortOrder = 0;

    #[ORM\ManyToOne(targetEntity: MediaItem::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['product_group:read', 'product_group:write'])]
    private ?MediaItem $featuredImage = null;

    // SEO fields
    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['product_group:read', 'product_group:write'])]
    private ?string $metaTitle = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['product_group:read', 'product_group:write'])]
    private ?string $metaDescription = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['product_group:read', 'product_group:write'])]
    private ?string $metaKeywords = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['product_group:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['product_group:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->children = new ArrayCollection();
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getSlug(): ?string
    {
        return $this->slug;
    }

    public function setSlug(string $slug): static
    {
        $this->slug = $slug;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setDescription(?string $description): static
    {
        $this->description = $description;
        return $this;
    }

    public function getProductGroupCode(): ?string
    {
        return $this->productGroupCode;
    }

    public function setProductGroupCode(?string $productGroupCode): static
    {
        $this->productGroupCode = $productGroupCode;
        return $this;
    }

    public function getParent(): ?ProductGroup
    {
        return $this->parent;
    }

    public function setParent(?ProductGroup $parent): static
    {
        $this->parent = $parent;
        return $this;
    }

    /**
     * @return Collection<int, ProductGroup>
     */
    public function getChildren(): Collection
    {
        return $this->children;
    }

    public function addChild(ProductGroup $child): static
    {
        if (!$this->children->contains($child)) {
            $this->children->add($child);
            $child->setParent($this);
        }
        return $this;
    }

    public function removeChild(ProductGroup $child): static
    {
        if ($this->children->removeElement($child)) {
            if ($child->getParent() === $this) {
                $child->setParent(null);
            }
        }
        return $this;
    }

    public function getLevel(): int
    {
        return $this->level;
    }

    public function setLevel(int $level): static
    {
        $this->level = $level;
        return $this;
    }

    public function getTotalProducts(): int
    {
        return $this->totalProducts;
    }

    public function setTotalProducts(int $totalProducts): static
    {
        $this->totalProducts = $totalProducts;
        return $this;
    }

    public function isShowOnHomepage(): bool
    {
        return $this->showOnHomepage;
    }

    public function setShowOnHomepage(bool $showOnHomepage): static
    {
        $this->showOnHomepage = $showOnHomepage;
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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
        return $this;
    }

    public function getFeaturedImage(): ?MediaItem
    {
        return $this->featuredImage;
    }

    public function setFeaturedImage(?MediaItem $featuredImage): static
    {
        $this->featuredImage = $featuredImage;
        return $this;
    }

    public function getMetaTitle(): ?string
    {
        return $this->metaTitle;
    }

    public function setMetaTitle(?string $metaTitle): static
    {
        $this->metaTitle = $metaTitle;
        return $this;
    }

    public function getMetaDescription(): ?string
    {
        return $this->metaDescription;
    }

    public function setMetaDescription(?string $metaDescription): static
    {
        $this->metaDescription = $metaDescription;
        return $this;
    }

    public function getMetaKeywords(): ?string
    {
        return $this->metaKeywords;
    }

    public function setMetaKeywords(?string $metaKeywords): static
    {
        $this->metaKeywords = $metaKeywords;
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
