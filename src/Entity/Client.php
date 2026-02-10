<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use App\State\Processor\ClientUsersProcessor;
use Doctrine\ORM\Mapping as ORM;
use App\Repository\ClientRepository;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['client:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['client:read']],
            denormalizationContext: ['groups' => ['client:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['client:read', 'client:read:details']]
        ),
        new Put(
            normalizationContext: ['groups' => ['client:read']],
            denormalizationContext: ['groups' => ['client:write']],
            processor: ClientUsersProcessor::class
        ),
        new Patch(
            normalizationContext: ['groups' => ['client:read']],
            denormalizationContext: ['groups' => ['client:write']],
            processor: ClientUsersProcessor::class
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['client:read']],
    denormalizationContext: ['groups' => ['client:write']]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'partial',
    'code' => 'exact',
    'accountGroup.id' => 'exact'
])]
#[ApiFilter(BooleanFilter::class, properties: ['isArchived', 'isActive'])]
#[ORM\Entity(repositoryClass: ClientRepository::class)]
#[ORM\Table]
#[ORM\Index(name: "idx_client_code", columns: ["code"])]
#[ORM\Index(name: "idx_client_name", columns: ["name"])]
class Client
{
    #[ORM\Id]
    #[ORM\Column(type: "uuid", unique: true)]
    #[Groups(['client:read', 'user:read', 'order:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['client:read', 'client:write', 'user:read', 'order:read'])]
    private ?string $name = null;

    #[ORM\Column(length: 50, unique: true)]
    #[Assert\NotBlank]
    #[Assert\Length(min: 2, max: 50)]
    #[Groups(['client:read', 'client:write', 'user:read', 'order:read'])]
    private ?string $code = null;

    #[ORM\Column(type: "text", nullable: true)]
    #[Groups(['client:read', 'client:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['client:read', 'client:write'])]
    private ?string $address = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['client:read', 'client:write'])]
    private ?string $phoneNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email]
    #[Groups(['client:read', 'client:write'])]
    private ?string $email = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['client:read', 'client:write'])]
    private ?string $vatNumber = null;

    #[ORM\Column(type: "decimal", precision: 12, scale: 2, nullable: true)]
    #[Groups(['client:read', 'client:write'])]
    private ?string $purchaseLimit = null;

    #[ORM\Column(type: "decimal", precision: 12, scale: 2, nullable: true)]
    #[Groups(['client:read', 'client:write'])]
    private ?string $amountSpent = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['client:read', 'client:write'])]
    private ?string $otherPhone = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email]
    #[Groups(['client:read', 'client:write'])]
    private ?string $otherEmail = null;

    #[ORM\Column(length: 20, nullable: true)]
    #[Groups(['client:read', 'client:write'])]
    private ?string $fax = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['client:read', 'client:write'])]
    private ?string $web = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['client:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['client:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, User>
     */
    #[ORM\OneToMany(targetEntity: User::class, mappedBy: 'client')]
    #[Groups(['client:read:details'])]
    #[ApiProperty(readableLink: true, writableLink: false)]
    private Collection $users;

    /**
     * @var Collection<int, ClientProductPrice>
     */
    #[ORM\OneToMany(targetEntity: ClientProductPrice::class, mappedBy: 'client', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['client:read:details', 'client:write'])]
    private Collection $productPrices;

    /**
     * Maximum number of active users allowed for this client
     * Null means unlimited active users
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['client:read', 'client:write', 'user:read'])]
    #[Assert\PositiveOrZero]
    private ?int $maxActiveUsers = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['client:read', 'client:write', 'user:read', 'order:read'])]
    private bool $isActive = true;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['client:read', 'client:write', 'user:read', 'order:read'])]
    private bool $isArchived = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['client:read', 'client:write'])]
    private bool $isLegalEntity = false;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['client:read', 'client:write'])]
    private ?string $accountType = null;

    #[ORM\ManyToOne(targetEntity: AccountGroup::class)]
    #[ORM\JoinColumn(name: 'account_group_id', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
    #[Groups(['client:read', 'client:write'])]
    private ?AccountGroup $accountGroup = null;

    /**
     * @var Collection<int, Address>
     */
    #[ORM\OneToMany(targetEntity: Address::class, mappedBy: 'client', cascade: ['persist', 'remove'], orphanRemoval: true)]
    #[Groups(['client:read:details'])]
    #[ApiProperty(readableLink: true, writableLink: true)]
    private Collection $addresses;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->users = new ArrayCollection();
        $this->productPrices = new ArrayCollection();
        $this->addresses = new ArrayCollection();
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

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setCode(string $code): static
    {
        $this->code = $code;
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

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;
        return $this;
    }

    public function getPhoneNumber(): ?string
    {
        return $this->phoneNumber;
    }

    public function setPhoneNumber(?string $phoneNumber): static
    {
        $this->phoneNumber = $phoneNumber;
        return $this;
    }

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
        return $this;
    }

    public function getVatNumber(): ?string
    {
        return $this->vatNumber;
    }

    public function setVatNumber(?string $vatNumber): static
    {
        $this->vatNumber = $vatNumber;
        return $this;
    }

    public function getPurchaseLimit(): ?string
    {
        return $this->purchaseLimit;
    }

    public function setPurchaseLimit(?string $purchaseLimit): static
    {
        $this->purchaseLimit = $purchaseLimit;
        return $this;
    }

    public function getAmountSpent(): ?string
    {
        return $this->amountSpent;
    }

    public function setAmountSpent(?string $amountSpent): static
    {
        $this->amountSpent = $amountSpent;
        return $this;
    }

    public function getOtherPhone(): ?string
    {
        return $this->otherPhone;
    }

    public function setOtherPhone(?string $otherPhone): static
    {
        $this->otherPhone = $otherPhone;
        return $this;
    }

    public function getOtherEmail(): ?string
    {
        return $this->otherEmail;
    }

    public function setOtherEmail(?string $otherEmail): static
    {
        $this->otherEmail = $otherEmail;
        return $this;
    }

    public function getFax(): ?string
    {
        return $this->fax;
    }

    public function setFax(?string $fax): static
    {
        $this->fax = $fax;
        return $this;
    }

    public function getWeb(): ?string
    {
        return $this->web;
    }

    public function setWeb(?string $web): static
    {
        $this->web = $web;
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
     * @return Collection<int, User>
     */
    public function getUsers(): Collection
    {
        return $this->users;
    }

    public function addUser(User $user): static
    {
        if (!$this->users->contains($user)) {
            $this->users->add($user);
            $user->setClient($this);
        }
        return $this;
    }

    public function removeUser(User $user): static
    {
        if ($this->users->removeElement($user)) {
            if ($user->getClient() === $this) {
                $user->setClient(null);
            }
        }
        return $this;
    }

    /**
     * @return Collection<int, ClientProductPrice>
     */
    public function getProductPrices(): Collection
    {
        return $this->productPrices;
    }

    public function addProductPrice(ClientProductPrice $productPrice): static
    {
        if (!$this->productPrices->contains($productPrice)) {
            $this->productPrices->add($productPrice);
            $productPrice->setClient($this);
        }
        return $this;
    }

    public function removeProductPrice(ClientProductPrice $productPrice): static
    {
        if ($this->productPrices->removeElement($productPrice)) {
            if ($productPrice->getClient() === $this) {
                $productPrice->setClient(null);
            }
        }
        return $this;
    }

    public function getProductPrice(Product $product): ?ClientProductPrice
    {
        foreach ($this->productPrices as $productPrice) {
            if ($productPrice->getProduct()->getId() === $product->getId()) {
                return $productPrice;
            }
        }
        return null;
    }

    public function getIsActive(): bool
    {
        return $this->isActive;
    }

    public function setIsActive(bool $isActive): static
    {
        $this->isActive = $isActive;

        // If setting to active, ensure it's not archived
        if ($isActive && $this->isArchived) {
            $this->isArchived = false;
        }

        return $this;
    }

    public function getIsArchived(): bool
    {
        return $this->isArchived;
    }

    public function setIsArchived(bool $isArchived): static
    {
        $this->isArchived = $isArchived;

        // If setting to archived, ensure it's not active
        if ($isArchived && $this->isActive) {
            $this->isActive = false;
        }

        return $this;
    }

    public function getIsLegalEntity(): bool
    {
        return $this->isLegalEntity;
    }

    public function setIsLegalEntity(bool $isLegalEntity): static
    {
        $this->isLegalEntity = $isLegalEntity;
        return $this;
    }

    public function getAccountType(): ?string
    {
        return $this->accountType;
    }

    public function setAccountType(?string $accountType): static
    {
        $this->accountType = $accountType;
        return $this;
    }

    public function getAccountGroup(): ?AccountGroup
    {
        return $this->accountGroup;
    }

    public function setAccountGroup(?AccountGroup $accountGroup): static
    {
        $this->accountGroup = $accountGroup;
        return $this;
    }

    public function getMaxActiveUsers(): ?int
    {
        return $this->maxActiveUsers;
    }

    public function setMaxActiveUsers(?int $maxActiveUsers): static
    {
        $this->maxActiveUsers = $maxActiveUsers;
        return $this;
    }

    /**
     * Count the number of currently active users for this client
     */
    public function countActiveUsers(): int
    {
        return $this->users->filter(fn(User $user) => $user->getIsActive())->count();
    }

    /**
     * Check if the client can have more active users
     */
    public function canAddActiveUser(): bool
    {
        // If maxActiveUsers is null, there's no limit
        if ($this->maxActiveUsers === null) {
            return true;
        }

        return $this->countActiveUsers() < $this->maxActiveUsers;
    }

    /**
     * @return Collection<int, Address>
     */
    public function getAddresses(): Collection
    {
        return $this->addresses;
    }

    public function addAddress(Address $address): static
    {
        if (!$this->addresses->contains($address)) {
            $this->addresses->add($address);
            $address->setClient($this);
        }
        return $this;
    }

    public function removeAddress(Address $address): static
    {
        if ($this->addresses->removeElement($address)) {
            if ($address->getClient() === $this) {
                $address->setClient(null);
            }
        }
        return $this;
    }
}
