<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\BooleanFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use App\Repository\AddressRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['address:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['address:read']],
            denormalizationContext: ['groups' => ['address:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['address:read']]
        ),
        new Put(
            normalizationContext: ['groups' => ['address:read']],
            denormalizationContext: ['groups' => ['address:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['address:read']],
            denormalizationContext: ['groups' => ['address:write']]
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['address:read']],
    denormalizationContext: ['groups' => ['address:write']]
)]
#[ApiResource(
    uriTemplate: '/clients/{clientId}/addresses',
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['address:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['address:read']],
            denormalizationContext: ['groups' => ['address:write']]
        )
    ],
    uriVariables: [
        'clientId' => new Link(toProperty: 'client', fromClass: Client::class)
    ]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'street' => 'partial',
    'city' => 'partial',
    'postalCode' => 'exact',
    'client.id' => 'exact'
])]
#[ApiFilter(BooleanFilter::class, properties: ['isBilling', 'isDelivery', 'isActive'])]
#[ORM\Entity(repositoryClass: AddressRepository::class)]
#[ORM\Table(name: 'address')]
#[ORM\Index(name: "idx_address_client", columns: ["client_id"])]
class Address
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['address:read', 'client:read', 'client:read:details'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Client::class, inversedBy: 'addresses')]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['address:read', 'address:write'])]
    private ?Client $client = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['address:read', 'address:write', 'client:read', 'client:read:details'])]
    private ?string $street = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['address:read', 'address:write', 'client:read', 'client:read:details'])]
    private ?string $city = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['address:read', 'address:write', 'client:read', 'client:read:details'])]
    private ?string $postalCode = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['address:read', 'address:write', 'client:read', 'client:read:details'])]
    private ?Country $country = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['address:read', 'address:write', 'client:read', 'client:read:details'])]
    private bool $isBilling = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['address:read', 'address:write', 'client:read', 'client:read:details'])]
    private bool $isDelivery = false;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['address:read', 'address:write', 'client:read', 'client:read:details'])]
    private bool $isActive = true;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['address:read', 'address:write', 'client:read', 'client:read:details'])]
    private ?string $name = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['address:read', 'address:write'])]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email]
    #[Groups(['address:read', 'address:write'])]
    private ?string $email = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['address:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['address:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
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

    public function getClient(): ?Client
    {
        return $this->client;
    }

    public function setClient(?Client $client): static
    {
        $this->client = $client;
        return $this;
    }

    public function getStreet(): ?string
    {
        return $this->street;
    }

    public function setStreet(string $street): static
    {
        $this->street = $street;
        return $this;
    }

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(string $city): static
    {
        $this->city = $city;
        return $this;
    }

    public function getPostalCode(): ?string
    {
        return $this->postalCode;
    }

    public function setPostalCode(?string $postalCode): static
    {
        $this->postalCode = $postalCode;
        return $this;
    }

    public function getCountry(): ?Country
    {
        return $this->country;
    }

    public function setCountry(?Country $country): static
    {
        $this->country = $country;
        return $this;
    }

    public function getIsBilling(): bool
    {
        return $this->isBilling;
    }

    public function setIsBilling(bool $isBilling): static
    {
        $this->isBilling = $isBilling;
        return $this;
    }

    public function getIsDelivery(): bool
    {
        return $this->isDelivery;
    }

    public function setIsDelivery(bool $isDelivery): static
    {
        $this->isDelivery = $isDelivery;
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

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getPhone(): ?string
    {
        return $this->phone;
    }

    public function setPhone(?string $phone): static
    {
        $this->phone = $phone;
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
     * Get formatted full address string
     */
    public function getFullAddress(): string
    {
        $parts = array_filter([
            $this->street,
            $this->postalCode,
            $this->city,
            $this->country?->getName()
        ]);
        return implode(', ', $parts);
    }
}
