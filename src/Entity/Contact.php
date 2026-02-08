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
use App\Repository\ContactRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    operations: [
        new GetCollection(),
        new Post(),
        new Get(),
        new Put(),
        new Patch(),
        new Delete(),
    ],
    normalizationContext: ['groups' => ['contact:read']],
    denormalizationContext: ['groups' => ['contact:write']],
)]
#[ApiFilter(SearchFilter::class, properties: [
    'firstName' => 'partial',
    'lastName' => 'partial',
    'email' => 'partial',
    'accountId' => 'exact',
])]
#[ApiFilter(BooleanFilter::class, properties: ['isActive'])]
#[ORM\Entity(repositoryClass: ContactRepository::class)]
#[ORM\Table(name: 'contact_entity')]
class Contact
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    #[Groups(['contact:read'])]
    private ?int $id = null;

    #[ORM\Column(name: 'first_name', length: 255, nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $firstName = null;

    #[ORM\Column(name: 'last_name', length: 255, nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $lastName = null;

    #[ORM\Column(name: 'full_name', length: 255, nullable: true)]
    #[Groups(['contact:read'])]
    private ?string $fullName = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Assert\Email]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $email = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $phone = null;

    #[ORM\Column(name: 'phone_2', length: 255, nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $otherPhone = null;

    #[ORM\Column(name: 'home_phone', length: 255, nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $homePhone = null;

    #[ORM\Column(name: 'secondary_email', length: 255, nullable: true)]
    #[Assert\Email]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $otherEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $fax = null;

    #[ORM\Column(name: 'date_of_birth', type: 'date', nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?\DateTimeInterface $dateOfBirth = null;

    #[ORM\Column(name: 'is_active', type: 'integer', nullable: true, options: ['unsigned' => true])]
    #[Groups(['contact:read', 'contact:write'])]
    private ?int $isActive = 1;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $description = null;

    // Account ID is a UUID string (references client.id which is binary(16)/UUID)
    #[ORM\Column(name: 'account_id', type: 'string', length: 36, nullable: true)]
    #[Groups(['contact:read', 'contact:write'])]
    private ?string $accountId = null;

    #[ORM\Column(name: 'title_id', type: 'integer', nullable: true, options: ['unsigned' => true])]
    #[Groups(['contact:read', 'contact:write'])]
    private ?int $titleId = null;

    #[ORM\Column(name: 'department_id', type: 'integer', nullable: true, options: ['unsigned' => true])]
    #[Groups(['contact:read', 'contact:write'])]
    private ?int $departmentId = null;

    #[ORM\Column(name: 'support_person_id', type: 'integer', nullable: true, options: ['unsigned' => true])]
    #[Groups(['contact:read', 'contact:write'])]
    private ?int $supportPersonId = null;

    #[ORM\Column(name: 'level_of_support_id', type: 'integer', nullable: true, options: ['unsigned' => true])]
    #[Groups(['contact:read', 'contact:write'])]
    private ?int $supportLevelId = null;

    // System fields from existing schema
    #[ORM\Column(name: 'entity_type_id', type: 'smallint', options: ['unsigned' => true])]
    private int $entityTypeId = 1;

    #[ORM\Column(name: 'attribute_set_id', type: 'smallint', options: ['unsigned' => true])]
    private int $attributeSetId = 1;

    #[ORM\Column(type: 'datetime')]
    #[Groups(['contact:read'])]
    private ?\DateTimeInterface $created = null;

    #[ORM\Column(type: 'datetime')]
    #[Groups(['contact:read'])]
    private ?\DateTimeInterface $modified = null;

    #[ORM\Column(name: 'entity_state_id', type: 'smallint', nullable: true, options: ['unsigned' => true])]
    private ?int $entityStateId = 1;

    public function __construct()
    {
        $this->created = new \DateTime();
        $this->modified = new \DateTime();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getFirstName(): ?string
    {
        return $this->firstName;
    }

    public function setFirstName(?string $firstName): static
    {
        $this->firstName = $firstName;
        $this->updateFullName();
        return $this;
    }

    public function getLastName(): ?string
    {
        return $this->lastName;
    }

    public function setLastName(?string $lastName): static
    {
        $this->lastName = $lastName;
        $this->updateFullName();
        return $this;
    }

    public function getFullName(): ?string
    {
        return $this->fullName;
    }

    private function updateFullName(): void
    {
        $this->fullName = trim(($this->firstName ?? '') . ' ' . ($this->lastName ?? ''));
        if (empty($this->fullName)) {
            $this->fullName = null;
        }
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

    public function getOtherPhone(): ?string
    {
        return $this->otherPhone;
    }

    public function setOtherPhone(?string $otherPhone): static
    {
        $this->otherPhone = $otherPhone;
        return $this;
    }

    public function getHomePhone(): ?string
    {
        return $this->homePhone;
    }

    public function setHomePhone(?string $homePhone): static
    {
        $this->homePhone = $homePhone;
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

    public function getDateOfBirth(): ?\DateTimeInterface
    {
        return $this->dateOfBirth;
    }

    public function setDateOfBirth(?\DateTimeInterface $dateOfBirth): static
    {
        $this->dateOfBirth = $dateOfBirth;
        return $this;
    }

    public function getIsActive(): ?int
    {
        return $this->isActive;
    }

    public function setIsActive(?int $isActive): static
    {
        $this->isActive = $isActive;
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

    public function getAccountId(): ?string
    {
        return $this->accountId;
    }

    public function setAccountId(?string $accountId): static
    {
        $this->accountId = $accountId;
        return $this;
    }

    public function getTitleId(): ?int
    {
        return $this->titleId;
    }

    public function setTitleId(?int $titleId): static
    {
        $this->titleId = $titleId;
        return $this;
    }

    public function getDepartmentId(): ?int
    {
        return $this->departmentId;
    }

    public function setDepartmentId(?int $departmentId): static
    {
        $this->departmentId = $departmentId;
        return $this;
    }

    public function getSupportPersonId(): ?int
    {
        return $this->supportPersonId;
    }

    public function setSupportPersonId(?int $supportPersonId): static
    {
        $this->supportPersonId = $supportPersonId;
        return $this;
    }

    public function getSupportLevelId(): ?int
    {
        return $this->supportLevelId;
    }

    public function setSupportLevelId(?int $supportLevelId): static
    {
        $this->supportLevelId = $supportLevelId;
        return $this;
    }

    public function getCreated(): ?\DateTimeInterface
    {
        return $this->created;
    }

    public function setCreated(\DateTimeInterface $created): static
    {
        $this->created = $created;
        return $this;
    }

    public function getModified(): ?\DateTimeInterface
    {
        return $this->modified;
    }

    public function setModified(\DateTimeInterface $modified): static
    {
        $this->modified = $modified;
        return $this;
    }

    #[ORM\PrePersist]
    #[ORM\PreUpdate]
    public function updateTimestamps(): void
    {
        $this->modified = new \DateTime();
        if ($this->created === null) {
            $this->created = new \DateTime();
        }
    }
}
