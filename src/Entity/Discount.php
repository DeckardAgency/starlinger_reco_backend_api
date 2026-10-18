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
use App\Repository\DiscountRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\SerializedName;

use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * A price adjustment for one or more clients: ONE signed percentage applied to
 * every product (optionally narrowed by product type, or to specific products via
 * ProductDiscount links). Negative = discount (price down, shown as "-X%"),
 * positive = markup (price up, shown to the client as a plain price).
 *
 * A discount always targets at least one client; there is no "global" discount.
 * When several apply, product-specific links beat client-wide ones and within a
 * tier the lowest id wins (see DiscountResolver).
 */
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
#[ApiFilter(OrderFilter::class, properties: ['id', 'name', 'isActive', 'adjustmentPercent'])]
#[ORM\Entity(repositoryClass: DiscountRepository::class)]
#[ORM\Table]
#[ORM\Index(name: 'idx_discount_active', columns: ['is_active'])]
class Discount
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['discount:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['discount:read', 'discount:write'])]
    private ?string $name = null;

    #[ORM\Column(type: 'boolean')]
    #[Groups(['discount:read', 'discount:write'])]
    #[SerializedName('isActive')]
    private bool $isActive = true;

    /**
     * Signed percentage as a decimal string (e.g. "-20.0000" = 20 % off,
     * "15.0000" = 15 % markup). Never 0, never -100 or below.
     */
    #[ORM\Column(name: 'adjustment_percent', type: 'decimal', precision: 12, scale: 4)]
    #[Assert\NotNull(message: 'Enter the adjustment in percent.')]
    #[Assert\Type(type: 'numeric', message: 'The adjustment must be a number.')]
    #[Assert\GreaterThan(value: -100, message: 'A discount must be less than 100 %.')]
    #[Assert\LessThanOrEqual(value: 999, message: 'A markup cannot exceed 999 %.')]
    #[Assert\NotEqualTo(value: 0, message: 'An adjustment of 0 % does nothing: use a negative value for a discount or a positive one for a markup.')]
    #[Groups(['discount:read', 'discount:write'])]
    private ?string $adjustmentPercent = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['discount:read', 'discount:write'])]
    private ?string $rules = null;

    /**
     * Optional list of product types this discount applies to (e.g. ['VT'], ['ET'], ['VT','ET']).
     * Empty/null means no product-type restriction (applies to all products that pass other filters).
     *
     * @var string[]|null
     */
    #[ORM\Column(name: 'product_types', type: 'json', nullable: true)]
    #[Groups(['discount:read', 'discount:write'])]
    private ?array $productTypes = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['discount:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['discount:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * The clients this adjustment applies to. Required: a discount without
     * clients applies to nobody.
     *
     * @var Collection<int, Client>
     */
    #[ORM\ManyToMany(targetEntity: Client::class)]
    #[ORM\JoinTable(name: 'discount_client')]
    #[Assert\Count(min: 1, minMessage: 'Select at least one client.')]
    #[Groups(['discount:read', 'discount:write'])]
    #[ApiProperty(readableLink: false, writableLink: false)]
    private Collection $clients;

    public function __construct()
    {
        $this->clients = new ArrayCollection();
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

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;
        return $this;
    }

    public function getAdjustmentPercent(): ?string
    {
        return $this->adjustmentPercent;
    }

    public function setAdjustmentPercent(?string $adjustmentPercent): static
    {
        $this->adjustmentPercent = $adjustmentPercent;
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

    /**
     * @return string[]|null
     */
    public function getProductTypes(): ?array
    {
        return $this->productTypes;
    }

    /**
     * @param string[]|null $productTypes
     */
    public function setProductTypes(?array $productTypes): static
    {
        $this->productTypes = $productTypes ?: null;
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

    /**
     * @return Collection<int, Client>
     */
    public function getClients(): Collection
    {
        return $this->clients;
    }

    public function addClient(Client $client): static
    {
        if (!$this->clients->contains($client)) {
            $this->clients->add($client);
        }
        return $this;
    }

    public function removeClient(Client $client): static
    {
        $this->clients->removeElement($client);
        return $this;
    }
}
