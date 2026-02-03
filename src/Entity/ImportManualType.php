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
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Gedmo\Mapping\Annotation as Gedmo;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['import_manual_type:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['import_manual_type:read']],
            denormalizationContext: ['groups' => ['import_manual_type:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['import_manual_type:read']]
        ),
        new Put(
            normalizationContext: ['groups' => ['import_manual_type:read']],
            denormalizationContext: ['groups' => ['import_manual_type:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['import_manual_type:read']],
            denormalizationContext: ['groups' => ['import_manual_type:write']]
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['import_manual_type:read']],
    denormalizationContext: ['groups' => ['import_manual_type:write']]
)]
#[ApiFilter(SearchFilter::class, properties: ['name' => 'partial', 'manualTypeCode' => 'exact'])]
#[ORM\Entity]
#[ORM\Table(name: 'import_manual_type_entity')]
class ImportManualType
{
    #[ORM\Id]
    #[ORM\Column(type: "uuid", unique: true)]
    #[Groups(['import_manual_type:read', 'import_manual:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['import_manual_type:read', 'import_manual_type:write', 'import_manual:read'])]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['import_manual_type:read', 'import_manual_type:write', 'import_manual:read'])]
    private ?string $managerCode = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['import_manual_type:read', 'import_manual_type:write', 'import_manual:read'])]
    private ?string $method = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['import_manual_type:read', 'import_manual_type:write', 'import_manual:read'])]
    private ?int $estimatedDuration = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['import_manual_type:read', 'import_manual_type:write', 'import_manual:read'])]
    private ?string $manualTypeCode = null;

    #[ORM\Column(type: 'boolean', nullable: true)]
    #[Groups(['import_manual_type:read', 'import_manual_type:write', 'import_manual:read'])]
    private ?bool $sendEmail = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['import_manual_type:read', 'import_manual_type:write', 'import_manual:read'])]
    private ?string $emailTemplateCode = null;

    /**
     * Legacy database ID for migration
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['import_manual_type:read'])]
    private ?int $legacyId = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['import_manual_type:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['import_manual_type:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'type', targetEntity: ImportManual::class)]
    private Collection $importManuals;

    public function __construct()
    {
        $this->id = Uuid::v4();
        $this->importManuals = new ArrayCollection();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
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

    public function getManagerCode(): ?string
    {
        return $this->managerCode;
    }

    public function setManagerCode(?string $managerCode): static
    {
        $this->managerCode = $managerCode;
        return $this;
    }

    public function getMethod(): ?string
    {
        return $this->method;
    }

    public function setMethod(?string $method): static
    {
        $this->method = $method;
        return $this;
    }

    public function getEstimatedDuration(): ?int
    {
        return $this->estimatedDuration;
    }

    public function setEstimatedDuration(?int $estimatedDuration): static
    {
        $this->estimatedDuration = $estimatedDuration;
        return $this;
    }

    public function getManualTypeCode(): ?string
    {
        return $this->manualTypeCode;
    }

    public function setManualTypeCode(?string $manualTypeCode): static
    {
        $this->manualTypeCode = $manualTypeCode;
        return $this;
    }

    public function getSendEmail(): ?bool
    {
        return $this->sendEmail;
    }

    public function setSendEmail(?bool $sendEmail): static
    {
        $this->sendEmail = $sendEmail;
        return $this;
    }

    public function getEmailTemplateCode(): ?string
    {
        return $this->emailTemplateCode;
    }

    public function setEmailTemplateCode(?string $emailTemplateCode): static
    {
        $this->emailTemplateCode = $emailTemplateCode;
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

    /**
     * @return Collection<int, ImportManual>
     */
    public function getImportManuals(): Collection
    {
        return $this->importManuals;
    }

    public function addImportManual(ImportManual $importManual): static
    {
        if (!$this->importManuals->contains($importManual)) {
            $this->importManuals->add($importManual);
            $importManual->setType($this);
        }
        return $this;
    }

    public function removeImportManual(ImportManual $importManual): static
    {
        if ($this->importManuals->removeElement($importManual)) {
            if ($importManual->getType() === $this) {
                $importManual->setType(null);
            }
        }
        return $this;
    }
}
