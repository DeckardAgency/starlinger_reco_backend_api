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
use App\Filter\NoClientFilter;
use App\Filter\UserSearchFilter;
use App\Repository\UserRepository;
use App\State\Processor\UserPasswordHasher;
use App\Validator\ActiveUserLimit;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Bridge\Doctrine\Validator\Constraints\UniqueEntity;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT_ADMIN')"),
        new Post(
            security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT_ADMIN')",
            validationContext: ['groups' => ['Default', 'user:create']],
            processor: UserPasswordHasher::class
        ),
        new Get(security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT_ADMIN') or object == user"),
        new Put(
            security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT_ADMIN') or object == user",
            processor: UserPasswordHasher::class
        ),
        new Patch(
            security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT_ADMIN') or object == user",
            processor: UserPasswordHasher::class
        ),
        new Delete(security: "is_granted('ROLE_ADMIN') or is_granted('ROLE_CLIENT_ADMIN')"),
    ],
    normalizationContext: ['groups' => ['user:read']],
    denormalizationContext: ['groups' => ['user:create', 'user:update']],
)]
#[ApiFilter(SearchFilter::class, properties: ['email' => 'exact', 'client.code' => 'exact', 'client.id' => 'exact', 'roles' => 'partial'])]
#[ApiFilter(OrderFilter::class, properties: ['id', 'email', 'firstName', 'lastName'])]
#[ApiFilter(NoClientFilter::class)]
#[ApiFilter(UserSearchFilter::class)]
#[ORM\Entity(repositoryClass: UserRepository::class)]
#[ORM\Table(name: '`user`')]
#[UniqueEntity('email')]
#[ActiveUserLimit]
class User implements UserInterface, PasswordAuthenticatedUserInterface
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['user:read', 'order:read', 'client:read:details'])]
    private ?int $id = null;

    #[ORM\Column(length: 180, unique: true)]
    #[Assert\NotBlank(groups: ['user:create'])]
    #[Assert\Email(groups: ['user:create', 'user:update'])]
    #[Groups(['user:read', 'user:create', 'user:update', 'order:read', 'client:read:details'])]
    private ?string $email = null;

    #[ORM\Column(length: 180, nullable: true)]
    #[Groups(['user:read', 'user:create', 'user:update'])]
    private ?string $username = null;

    #[ORM\Column]
    #[Groups(['user:read', 'user:create', 'user:update'])]
    private array $roles = [];

    /**
     * @var string The hashed password
     */
    #[ORM\Column]
    private ?string $password = null;

    /**
     * @var string|null The plain password - not stored in a database
     */
    #[Assert\NotBlank(groups: ['user:create'])]
    #[Groups(['user:create', 'user:update'])]
    private ?string $plainPassword = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(groups: ['user:create'])]
    #[Assert\Length(min: 2, max: 100, groups: ['user:create', 'user:update'])]
    #[Groups(['user:read', 'user:create', 'user:update', 'order:read', 'client:read:details'])]
    private ?string $firstName = null;

    #[ORM\Column(length: 100)]
    #[Assert\NotBlank(groups: ['user:create'])]
    #[Assert\Length(min: 2, max: 100, groups: ['user:create', 'user:update'])]
    #[Groups(['user:read', 'user:create', 'user:update', 'order:read', 'client:read:details'])]
    private ?string $lastName = null;

    #[ORM\Column(length: 15, nullable: true)]
    #[Groups(['user:read', 'user:create', 'user:update'])]
    private ?string $phoneNumber = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['user:read', 'user:create', 'user:update'])]
    private ?string $address = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['user:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['user:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, Order>
     */
    #[ORM\OneToMany(targetEntity: Order::class, mappedBy: 'user', orphanRemoval: false)]
    private Collection $orders;

    #[ORM\ManyToOne(targetEntity: Client::class, inversedBy: 'users')]
    #[Groups(['user:read', 'user:create', 'user:update', 'order:read'])]
    private ?Client $client = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['user:read', 'user:create', 'user:update'])]
    private bool $isActive = true;

    #[ORM\Column(type: 'integer', options: ['default' => 0])]
    private int $failedLoginAttempts = 0;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lockedUntil = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private ?\DateTimeInterface $lastFailedLoginAt = null;

    public function __construct()
    {
        $this->orders = new ArrayCollection();
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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(string $email): static
    {
        $this->email = $email;

        return $this;
    }

    public function getUsername(): ?string
    {
        return $this->username;
    }

    public function setUsername(?string $username): static
    {
        $this->username = $username;

        return $this;
    }

    /**
     * A visual identifier that represents this user.
     *
     * @see UserInterface
     */
    public function getUserIdentifier(): string
    {
        return (string) $this->email;
    }

    /**
     * @see UserInterface
     */
    public function getRoles(): array
    {
        $roles = $this->roles;
        // guarantee every user at least has ROLE_USER
        $roles[] = 'ROLE_USER';

        return array_unique($roles);
    }

    public function setRoles(array $roles): static
    {
        $this->roles = $roles;

        return $this;
    }

    /**
     * @see PasswordAuthenticatedUserInterface
     */
    public function getPassword(): string
    {
        return $this->password;
    }

    public function setPassword(string $password): static
    {
        $this->password = $password;

        return $this;
    }

    /**
     * @see UserInterface
     */
    public function eraseCredentials(): void
    {
        // If you store any temporary, sensitive data on the user, clear it here
        $this->plainPassword = null;
    }

    public function getPlainPassword(): ?string
    {
        return $this->plainPassword;
    }

    public function setPlainPassword(?string $plainPassword): self
    {
        $this->plainPassword = $plainPassword;

        return $this;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(string $firstName): static
    {
        $this->firstName = $firstName;

        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(string $lastName): static
    {
        $this->lastName = $lastName;

        return $this;
    }

    public function getFullName(): string
    {
        return $this->firstName . ' ' . $this->lastName;
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

    public function getAddress(): ?string
    {
        return $this->address;
    }

    public function setAddress(?string $address): static
    {
        $this->address = $address;

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
     * @return Collection<int, Order>
     */
    public function getOrders(): Collection
    {
        return $this->orders;
    }

    public function addOrder(Order $order): static
    {
        if (!$this->orders->contains($order)) {
            $this->orders->add($order);
            $order->setUser($this);
        }

        return $this;
    }

    public function removeOrder(Order $order): static
    {
        if ($this->orders->removeElement($order)) {
            // set the owning side to null (unless already changed)
            if ($order->getUser() === $this) {
                $order->setUser(null);
            }
        }

        return $this;
    }

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;

        return $this;
    }

    /**
     * Check if the user has a client
     */
    public function hasClient(): bool
    {
        return $this->client !== null;
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

    public function getFailedLoginAttempts(): int
    {
        return $this->failedLoginAttempts;
    }

    public function setFailedLoginAttempts(int $failedLoginAttempts): static
    {
        $this->failedLoginAttempts = $failedLoginAttempts;
        return $this;
    }

    public function incrementFailedLoginAttempts(): static
    {
        $this->failedLoginAttempts++;
        $this->lastFailedLoginAt = new \DateTime();
        return $this;
    }

    public function resetFailedLoginAttempts(): static
    {
        $this->failedLoginAttempts = 0;
        $this->lastFailedLoginAt = null;
        $this->lockedUntil = null;
        return $this;
    }

    public function getLockedUntil(): ?\DateTimeInterface
    {
        return $this->lockedUntil;
    }

    public function setLockedUntil(?\DateTimeInterface $lockedUntil): static
    {
        $this->lockedUntil = $lockedUntil;
        return $this;
    }

    public function getLastFailedLoginAt(): ?\DateTimeInterface
    {
        return $this->lastFailedLoginAt;
    }

    public function setLastFailedLoginAt(?\DateTimeInterface $lastFailedLoginAt): static
    {
        $this->lastFailedLoginAt = $lastFailedLoginAt;
        return $this;
    }

    /**
     * Check if the user account is currently locked
     */
    public function isLocked(): bool
    {
        return $this->lockedUntil !== null && $this->lockedUntil > new \DateTime();
    }

    /**
     * Lock the account for a specified duration
     */
    public function lockAccount(int $minutes = 30): static
    {
        $this->lockedUntil = new \DateTime(sprintf('+%d minutes', $minutes));
        return $this;
    }

    /**
     * Get remaining lock time in minutes (returns 0 if not locked)
     */
    public function getRemainingLockMinutes(): int
    {
        if (!$this->isLocked()) {
            return 0;
        }

        $now = new \DateTime();
        $diff = $this->lockedUntil->getTimestamp() - $now->getTimestamp();
        return max(0, (int)ceil($diff / 60));
    }
}
