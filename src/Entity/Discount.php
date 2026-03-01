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
#[ApiFilter(OrderFilter::class, properties: ['id', 'name', 'isActive', 'priority', 'dateValidFrom', 'dateValidTo'])]
#[ORM\Entity(repositoryClass: DiscountRepository::class)]
#[ORM\Table]
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

    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(['discount:read', 'discount:write'])]
    private ?\DateTimeInterface $dateValidFrom = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(['discount:read', 'discount:write'])]
    private ?\DateTimeInterface $dateValidTo = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['discount:read', 'discount:write'])]
    private ?int $priority = 0;

    #[ORM\Column(type: 'decimal', precision: 12, scale: 4, nullable: true)]
    #[Groups(['discount:read', 'discount:write'])]
    private ?string $discountPercent = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['discount:read', 'discount:write'])]
    private ?string $rules = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['discount:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['discount:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, AccountGroup>
     */
    #[ORM\ManyToMany(targetEntity: AccountGroup::class)]
    #[ORM\JoinTable(name: 'discount_account_group')]
    #[Groups(['discount:read', 'discount:write'])]
    #[ApiProperty(readableLink: false, writableLink: false)]
    private Collection $accountGroups;

    /**
     * @var Collection<int, Client>
     */
    #[ORM\ManyToMany(targetEntity: Client::class)]
    #[ORM\JoinTable(name: 'discount_client')]
    #[Groups(['discount:read', 'discount:write'])]
    #[ApiProperty(readableLink: false, writableLink: false)]
    private Collection $clients;

    public function __construct()
    {
        $this->accountGroups = new ArrayCollection();
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

    public function getPriority(): ?int
    {
        return $this->priority;
    }

    public function setPriority(?int $priority): static
    {
        $this->priority = $priority;
        return $this;
    }

    public function getDiscountPercent(): ?string
    {
        return $this->discountPercent;
    }

    public function setDiscountPercent(?string $discountPercent): static
    {
        $this->discountPercent = $discountPercent;
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
     * @return Collection<int, AccountGroup>
     */
    public function getAccountGroups(): Collection
    {
        return $this->accountGroups;
    }

    public function addAccountGroup(AccountGroup $accountGroup): static
    {
        if (!$this->accountGroups->contains($accountGroup)) {
            $this->accountGroups->add($accountGroup);
        }
        return $this;
    }

    public function removeAccountGroup(AccountGroup $accountGroup): static
    {
        $this->accountGroups->removeElement($accountGroup);
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
