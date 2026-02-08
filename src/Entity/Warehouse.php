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
use ApiPlatform\Metadata\ApiProperty;
use App\Repository\WarehouseRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Serializer\Annotation\SerializedName;
use Symfony\Component\Uid\Uuid;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['warehouse:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['warehouse:read']],
            denormalizationContext: ['groups' => ['warehouse:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['warehouse:read']]
        ),
        new Put(
            normalizationContext: ['groups' => ['warehouse:read']],
            denormalizationContext: ['groups' => ['warehouse:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['warehouse:read']],
            denormalizationContext: ['groups' => ['warehouse:write']]
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['warehouse:read']],
    denormalizationContext: ['groups' => ['warehouse:write']]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'name' => 'partial',
    'code' => 'exact',
    'city' => 'partial'
])]
#[ApiFilter(BooleanFilter::class, properties: ['isActive', 'showAsLocation'])]
#[ORM\Entity(repositoryClass: WarehouseRepository::class)]
#[ORM\Table]
class Warehouse
{
    #[ORM\Id]
    #[ORM\Column(type: "uuid", unique: true)]
    #[Groups(['warehouse:read', 'delivery_type:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255)]
    #[Assert\NotBlank]
    #[Groups(['warehouse:read', 'warehouse:write', 'delivery_type:read'])]
    private ?string $name = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?string $code = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?string $address = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?string $city = null;

    #[ORM\ManyToOne(targetEntity: Country::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?Country $country = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?string $email = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?string $phone = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?string $officeName = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?string $url = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?string $latitude = null;

    #[ORM\Column(length: 50, nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?string $longitude = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private bool $showAsLocation = false;

    // Working hours
    #[ORM\Column(type: 'time', nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?\DateTimeInterface $mondayFrom = null;

    #[ORM\Column(type: 'time', nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?\DateTimeInterface $mondayTo = null;

    #[ORM\Column(type: 'time', nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?\DateTimeInterface $tuesdayFrom = null;

    #[ORM\Column(type: 'time', nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?\DateTimeInterface $tuesdayTo = null;

    #[ORM\Column(type: 'time', nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?\DateTimeInterface $wednesdayFrom = null;

    #[ORM\Column(type: 'time', nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?\DateTimeInterface $wednesdayTo = null;

    #[ORM\Column(type: 'time', nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?\DateTimeInterface $thursdayFrom = null;

    #[ORM\Column(type: 'time', nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?\DateTimeInterface $thursdayTo = null;

    #[ORM\Column(type: 'time', nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?\DateTimeInterface $fridayFrom = null;

    #[ORM\Column(type: 'time', nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?\DateTimeInterface $fridayTo = null;

    #[ORM\Column(type: 'time', nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?\DateTimeInterface $saturdayFrom = null;

    #[ORM\Column(type: 'time', nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?\DateTimeInterface $saturdayTo = null;

    #[ORM\Column(type: 'time', nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?\DateTimeInterface $sundayFrom = null;

    #[ORM\Column(type: 'time', nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?\DateTimeInterface $sundayTo = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?string $contactPerson = null;

    #[ORM\Column(type: 'boolean', options: ['default' => true])]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    #[SerializedName('isActive')]
    private bool $isActive = true;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    #[SerializedName('readyForShop')]
    private bool $readyForShop = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    #[SerializedName('keepUrl')]
    private bool $keepUrl = false;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    #[SerializedName('autoGenerateUrl')]
    private bool $autoGenerateUrl = false;

    #[ORM\OneToMany(targetEntity: MediaItem::class, mappedBy: 'warehouseDocument', cascade: ['persist'], orphanRemoval: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    #[ApiProperty(writableLink: false)]
    private Collection $documents;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['warehouse:read', 'warehouse:write'])]
    private ?int $remoteId = null;

    /**
     * Legacy database ID for migration
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['warehouse:read'])]
    private ?int $legacyId = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['warehouse:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['warehouse:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->documents = new ArrayCollection();
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

    public function setCode(?string $code): static
    {
        $this->code = $code;
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

    public function getCity(): ?string
    {
        return $this->city;
    }

    public function setCity(?string $city): static
    {
        $this->city = $city;
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

    public function getEmail(): ?string
    {
        return $this->email;
    }

    public function setEmail(?string $email): static
    {
        $this->email = $email;
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

    public function getOfficeName(): ?string
    {
        return $this->officeName;
    }

    public function setOfficeName(?string $officeName): static
    {
        $this->officeName = $officeName;
        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;
        return $this;
    }

    public function getLatitude(): ?string
    {
        return $this->latitude;
    }

    public function setLatitude(?string $latitude): static
    {
        $this->latitude = $latitude;
        return $this;
    }

    public function getLongitude(): ?string
    {
        return $this->longitude;
    }

    public function setLongitude(?string $longitude): static
    {
        $this->longitude = $longitude;
        return $this;
    }

    #[SerializedName('showAsLocation')]
    public function getShowAsLocation(): bool
    {
        return $this->showAsLocation;
    }

    public function setShowAsLocation(bool $showAsLocation): static
    {
        $this->showAsLocation = $showAsLocation;
        return $this;
    }

    // Working hours getters/setters
    public function getMondayFrom(): ?\DateTimeInterface
    {
        return $this->mondayFrom;
    }

    public function setMondayFrom(?\DateTimeInterface $mondayFrom): static
    {
        $this->mondayFrom = $mondayFrom;
        return $this;
    }

    public function getMondayTo(): ?\DateTimeInterface
    {
        return $this->mondayTo;
    }

    public function setMondayTo(?\DateTimeInterface $mondayTo): static
    {
        $this->mondayTo = $mondayTo;
        return $this;
    }

    public function getTuesdayFrom(): ?\DateTimeInterface
    {
        return $this->tuesdayFrom;
    }

    public function setTuesdayFrom(?\DateTimeInterface $tuesdayFrom): static
    {
        $this->tuesdayFrom = $tuesdayFrom;
        return $this;
    }

    public function getTuesdayTo(): ?\DateTimeInterface
    {
        return $this->tuesdayTo;
    }

    public function setTuesdayTo(?\DateTimeInterface $tuesdayTo): static
    {
        $this->tuesdayTo = $tuesdayTo;
        return $this;
    }

    public function getWednesdayFrom(): ?\DateTimeInterface
    {
        return $this->wednesdayFrom;
    }

    public function setWednesdayFrom(?\DateTimeInterface $wednesdayFrom): static
    {
        $this->wednesdayFrom = $wednesdayFrom;
        return $this;
    }

    public function getWednesdayTo(): ?\DateTimeInterface
    {
        return $this->wednesdayTo;
    }

    public function setWednesdayTo(?\DateTimeInterface $wednesdayTo): static
    {
        $this->wednesdayTo = $wednesdayTo;
        return $this;
    }

    public function getThursdayFrom(): ?\DateTimeInterface
    {
        return $this->thursdayFrom;
    }

    public function setThursdayFrom(?\DateTimeInterface $thursdayFrom): static
    {
        $this->thursdayFrom = $thursdayFrom;
        return $this;
    }

    public function getThursdayTo(): ?\DateTimeInterface
    {
        return $this->thursdayTo;
    }

    public function setThursdayTo(?\DateTimeInterface $thursdayTo): static
    {
        $this->thursdayTo = $thursdayTo;
        return $this;
    }

    public function getFridayFrom(): ?\DateTimeInterface
    {
        return $this->fridayFrom;
    }

    public function setFridayFrom(?\DateTimeInterface $fridayFrom): static
    {
        $this->fridayFrom = $fridayFrom;
        return $this;
    }

    public function getFridayTo(): ?\DateTimeInterface
    {
        return $this->fridayTo;
    }

    public function setFridayTo(?\DateTimeInterface $fridayTo): static
    {
        $this->fridayTo = $fridayTo;
        return $this;
    }

    public function getSaturdayFrom(): ?\DateTimeInterface
    {
        return $this->saturdayFrom;
    }

    public function setSaturdayFrom(?\DateTimeInterface $saturdayFrom): static
    {
        $this->saturdayFrom = $saturdayFrom;
        return $this;
    }

    public function getSaturdayTo(): ?\DateTimeInterface
    {
        return $this->saturdayTo;
    }

    public function setSaturdayTo(?\DateTimeInterface $saturdayTo): static
    {
        $this->saturdayTo = $saturdayTo;
        return $this;
    }

    public function getSundayFrom(): ?\DateTimeInterface
    {
        return $this->sundayFrom;
    }

    public function setSundayFrom(?\DateTimeInterface $sundayFrom): static
    {
        $this->sundayFrom = $sundayFrom;
        return $this;
    }

    public function getSundayTo(): ?\DateTimeInterface
    {
        return $this->sundayTo;
    }

    public function setSundayTo(?\DateTimeInterface $sundayTo): static
    {
        $this->sundayTo = $sundayTo;
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

    public function getContactPerson(): ?string
    {
        return $this->contactPerson;
    }

    public function setContactPerson(?string $contactPerson): static
    {
        $this->contactPerson = $contactPerson;
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

    public function getReadyForShop(): bool
    {
        return $this->readyForShop;
    }

    public function setReadyForShop(bool $readyForShop): static
    {
        $this->readyForShop = $readyForShop;
        return $this;
    }

    public function getKeepUrl(): bool
    {
        return $this->keepUrl;
    }

    public function setKeepUrl(bool $keepUrl): static
    {
        $this->keepUrl = $keepUrl;
        return $this;
    }

    public function getAutoGenerateUrl(): bool
    {
        return $this->autoGenerateUrl;
    }

    public function setAutoGenerateUrl(bool $autoGenerateUrl): static
    {
        $this->autoGenerateUrl = $autoGenerateUrl;
        return $this;
    }

    /**
     * @return Collection<int, MediaItem>
     */
    public function getDocuments(): Collection
    {
        return $this->documents;
    }

    public function addDocument(MediaItem $document): static
    {
        if (!$this->documents->contains($document)) {
            $this->documents->add($document);
            $document->setWarehouseDocument($this);
        }
        return $this;
    }

    public function removeDocument(MediaItem $document): static
    {
        if ($this->documents->removeElement($document)) {
            if ($document->getWarehouseDocument() === $this) {
                $document->setWarehouseDocument(null);
            }
        }
        return $this;
    }

    public function getRemoteId(): ?int
    {
        return $this->remoteId;
    }

    public function setRemoteId(?int $remoteId): static
    {
        $this->remoteId = $remoteId;
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
