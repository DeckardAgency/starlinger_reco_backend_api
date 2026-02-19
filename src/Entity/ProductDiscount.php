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
use App\Repository\ProductDiscountRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Gedmo\Mapping\Annotation as Gedmo;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['product_discount:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['product_discount:read']],
            denormalizationContext: ['groups' => ['product_discount:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['product_discount:read']]
        ),
        new Put(
            normalizationContext: ['groups' => ['product_discount:read']],
            denormalizationContext: ['groups' => ['product_discount:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['product_discount:read']],
            denormalizationContext: ['groups' => ['product_discount:write']]
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['product_discount:read']],
    denormalizationContext: ['groups' => ['product_discount:write']]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'productId' => 'exact'
])]
#[ORM\Entity(repositoryClass: ProductDiscountRepository::class)]
#[ORM\Table(name: 'product_discount')]
class ProductDiscount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['product_discount:read'])]
    private ?int $id = null;

    #[ORM\Column(name: 'product_id', type: 'integer', nullable: true)]
    #[Groups(['product_discount:read', 'product_discount:write'])]
    private ?int $productId = null;

    #[ORM\Column(name: 'discount_price_base', type: 'decimal', precision: 12, scale: 4, nullable: true)]
    #[Groups(['product_discount:read', 'product_discount:write'])]
    private ?string $discountPriceBase = null;

    #[ORM\Column(name: 'discount_price_retail', type: 'decimal', precision: 12, scale: 4, nullable: true)]
    #[Groups(['product_discount:read', 'product_discount:write'])]
    private ?string $discountPriceRetail = null;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    #[Groups(['product_discount:read', 'product_discount:write'])]
    private ?string $rebate = null;

    #[ORM\Column(type: 'integer', nullable: true, options: ['unsigned' => true])]
    #[Groups(['product_discount:read', 'product_discount:write'])]
    private ?int $type = null;

    #[ORM\Column(name: 'date_valid_from', type: 'datetime', nullable: true)]
    #[Groups(['product_discount:read', 'product_discount:write'])]
    private ?\DateTimeInterface $dateValidFrom = null;

    #[ORM\Column(name: 'date_valid_to', type: 'datetime', nullable: true)]
    #[Groups(['product_discount:read', 'product_discount:write'])]
    private ?\DateTimeInterface $dateValidTo = null;

    #[ORM\Column(name: 'applied_to', length: 255, nullable: true)]
    #[Groups(['product_discount:read', 'product_discount:write'])]
    private ?string $appliedTo = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['product_discount:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['product_discount:read'])]
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

    public function getProductId(): ?int
    {
        return $this->productId;
    }

    public function setProductId(?int $productId): static
    {
        $this->productId = $productId;
        return $this;
    }

    public function getDiscountPriceBase(): ?string
    {
        return $this->discountPriceBase;
    }

    public function setDiscountPriceBase(?string $discountPriceBase): static
    {
        $this->discountPriceBase = $discountPriceBase;
        return $this;
    }

    public function getDiscountPriceRetail(): ?string
    {
        return $this->discountPriceRetail;
    }

    public function setDiscountPriceRetail(?string $discountPriceRetail): static
    {
        $this->discountPriceRetail = $discountPriceRetail;
        return $this;
    }

    public function getRebate(): ?string
    {
        return $this->rebate;
    }

    public function setRebate(?string $rebate): static
    {
        $this->rebate = $rebate;
        return $this;
    }

    public function getType(): ?int
    {
        return $this->type;
    }

    public function setType(?int $type): static
    {
        $this->type = $type;
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

    public function getAppliedTo(): ?string
    {
        return $this->appliedTo;
    }

    public function setAppliedTo(?string $appliedTo): static
    {
        $this->appliedTo = $appliedTo;
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
