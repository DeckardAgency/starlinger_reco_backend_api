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
use App\Repository\PaymentTypeRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['payment_type:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['payment_type:read']],
            denormalizationContext: ['groups' => ['payment_type:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['payment_type:read']]
        ),
        new Put(
            normalizationContext: ['groups' => ['payment_type:read']],
            denormalizationContext: ['groups' => ['payment_type:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['payment_type:read']],
            denormalizationContext: ['groups' => ['payment_type:write']]
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['payment_type:read']],
    denormalizationContext: ['groups' => ['payment_type:write']]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'partial',
    'providerCode' => 'exact',
    'remoteCode' => 'exact'
])]
#[ApiFilter(BooleanFilter::class, properties: ['isActive', 'enableInstallments', 'allowRecurringPayment'])]
#[ORM\Entity(repositoryClass: PaymentTypeRepository::class)]
#[ORM\Table]
class PaymentType
{
    #[ORM\Id]
    #[ORM\Column(type: "uuid", unique: true)]
    #[Groups(['payment_type:read', 'order:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['payment_type:read', 'payment_type:write', 'order:read'])]
    private ?string $name = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['payment_type:read', 'payment_type:write'])]
    private ?string $shortDescription = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['payment_type:read', 'payment_type:write'])]
    private ?string $providerCode = null;

    #[ORM\Column(type: 'json', nullable: true)]
    #[Groups(['payment_type:read', 'payment_type:write'])]
    private ?array $configuration = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['payment_type:read', 'payment_type:write'])]
    private bool $enableInstallments = false;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['payment_type:read', 'payment_type:write'])]
    private ?string $remoteCode = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['payment_type:read', 'payment_type:write'])]
    private ?string $fiscalCode = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['payment_type:read', 'payment_type:write'])]
    private ?string $paymentFee = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['payment_type:read', 'payment_type:write'])]
    private ?string $minCartTotal = null;

    #[ORM\Column(type: 'decimal', precision: 10, scale: 2, nullable: true)]
    #[Groups(['payment_type:read', 'payment_type:write'])]
    private ?string $maxCartTotal = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['payment_type:read', 'payment_type:write'])]
    private ?string $color = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['payment_type:read', 'payment_type:write'])]
    private ?string $icon = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['payment_type:read', 'payment_type:write'])]
    private bool $allowRecurringPayment = false;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['payment_type:read', 'payment_type:write'])]
    private ?int $recurringDaysReminder = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['payment_type:read', 'payment_type:write'])]
    private bool $useAsDefault = false;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['payment_type:read', 'payment_type:write'])]
    private bool $isActive = true;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    #[Groups(['payment_type:read', 'payment_type:write'])]
    private int $sortOrder = 0;

    /**
     * Legacy database ID for migration
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['payment_type:read'])]
    private ?int $legacyId = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['payment_type:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['payment_type:read'])]
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

    public function getShortDescription(): ?string
    {
        return $this->shortDescription;
    }

    public function setShortDescription(?string $shortDescription): static
    {
        $this->shortDescription = $shortDescription;
        return $this;
    }

    public function getProviderCode(): ?string
    {
        return $this->providerCode;
    }

    public function setProviderCode(?string $providerCode): static
    {
        $this->providerCode = $providerCode;
        return $this;
    }

    public function getConfiguration(): ?array
    {
        return $this->configuration;
    }

    public function setConfiguration(?array $configuration): static
    {
        $this->configuration = $configuration;
        return $this;
    }

    public function isEnableInstallments(): bool
    {
        return $this->enableInstallments;
    }

    public function setEnableInstallments(bool $enableInstallments): static
    {
        $this->enableInstallments = $enableInstallments;
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

    public function getFiscalCode(): ?string
    {
        return $this->fiscalCode;
    }

    public function setFiscalCode(?string $fiscalCode): static
    {
        $this->fiscalCode = $fiscalCode;
        return $this;
    }

    public function getPaymentFee(): ?string
    {
        return $this->paymentFee;
    }

    public function setPaymentFee(?string $paymentFee): static
    {
        $this->paymentFee = $paymentFee;
        return $this;
    }

    public function getMinCartTotal(): ?string
    {
        return $this->minCartTotal;
    }

    public function setMinCartTotal(?string $minCartTotal): static
    {
        $this->minCartTotal = $minCartTotal;
        return $this;
    }

    public function getMaxCartTotal(): ?string
    {
        return $this->maxCartTotal;
    }

    public function setMaxCartTotal(?string $maxCartTotal): static
    {
        $this->maxCartTotal = $maxCartTotal;
        return $this;
    }

    public function getColor(): ?string
    {
        return $this->color;
    }

    public function setColor(?string $color): static
    {
        $this->color = $color;
        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setIcon(?string $icon): static
    {
        $this->icon = $icon;
        return $this;
    }

    public function isAllowRecurringPayment(): bool
    {
        return $this->allowRecurringPayment;
    }

    public function setAllowRecurringPayment(bool $allowRecurringPayment): static
    {
        $this->allowRecurringPayment = $allowRecurringPayment;
        return $this;
    }

    public function getRecurringDaysReminder(): ?int
    {
        return $this->recurringDaysReminder;
    }

    public function setRecurringDaysReminder(?int $recurringDaysReminder): static
    {
        $this->recurringDaysReminder = $recurringDaysReminder;
        return $this;
    }

    public function isUseAsDefault(): bool
    {
        return $this->useAsDefault;
    }

    public function setUseAsDefault(bool $useAsDefault): static
    {
        $this->useAsDefault = $useAsDefault;
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

    public function getSortOrder(): int
    {
        return $this->sortOrder;
    }

    public function setSortOrder(int $sortOrder): static
    {
        $this->sortOrder = $sortOrder;
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
