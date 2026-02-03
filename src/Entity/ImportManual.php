<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use ApiPlatform\Metadata\Put;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Uid\Uuid;
use Gedmo\Mapping\Annotation as Gedmo;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['import_manual:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['import_manual:read']],
            denormalizationContext: ['groups' => ['import_manual:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['import_manual:read']]
        ),
        new Put(
            normalizationContext: ['groups' => ['import_manual:read']],
            denormalizationContext: ['groups' => ['import_manual:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['import_manual:read']],
            denormalizationContext: ['groups' => ['import_manual:write']]
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['import_manual:read']],
    denormalizationContext: ['groups' => ['import_manual:write']],
    order: ['createdAt' => 'DESC']
)]
#[ApiFilter(SearchFilter::class, properties: [
    'filename' => 'partial',
    'fileSource' => 'partial',
    'type.id' => 'exact',
    'status.id' => 'exact',
    'user.id' => 'exact'
])]
#[ApiFilter(DateFilter::class, properties: ['dateStarted', 'dateFinished', 'createdAt'])]
#[ORM\Entity]
#[ORM\Table(name: 'import_manual_entity')]
class ImportManual
{
    #[ORM\Id]
    #[ORM\Column(type: "uuid", unique: true)]
    #[Groups(['import_manual:read'])]
    private ?Uuid $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['import_manual:read', 'import_manual:write'])]
    private ?string $file = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['import_manual:read', 'import_manual:write'])]
    private ?string $filename = null;

    #[ORM\Column(length: 5, nullable: true)]
    #[Groups(['import_manual:read', 'import_manual:write'])]
    private ?string $fileType = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['import_manual:read', 'import_manual:write'])]
    private ?string $size = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['import_manual:read', 'import_manual:write'])]
    private ?string $fileSource = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(['import_manual:read', 'import_manual:write'])]
    private ?\DateTimeInterface $dateStarted = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    #[Groups(['import_manual:read', 'import_manual:write'])]
    private ?\DateTimeInterface $dateFinished = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['import_manual:read', 'import_manual:write'])]
    private ?string $importResult = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['import_manual:read', 'import_manual:write'])]
    private ?int $rowsImported = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['import_manual:read', 'import_manual:write'])]
    private ?string $inputParameters = null;

    #[ORM\ManyToOne(targetEntity: ImportManualType::class, inversedBy: 'importManuals')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['import_manual:read', 'import_manual:write'])]
    private ?ImportManualType $type = null;

    #[ORM\ManyToOne(targetEntity: ImportManualStatus::class, inversedBy: 'importManuals')]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['import_manual:read', 'import_manual:write'])]
    private ?ImportManualStatus $status = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: true)]
    #[Groups(['import_manual:read', 'import_manual:write'])]
    private ?User $user = null;

    /**
     * Legacy database ID for migration
     */
    #[ORM\Column(type: 'integer', nullable: true)]
    #[Groups(['import_manual:read'])]
    private ?int $legacyId = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['import_manual:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['import_manual:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['import_manual:read'])]
    private ?string $createdBy = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['import_manual:read'])]
    private ?string $modifiedBy = null;

    public function __construct()
    {
        $this->id = Uuid::v4();
    }

    public function getId(): ?Uuid
    {
        return $this->id;
    }

    public function getFile(): ?string
    {
        return $this->file;
    }

    public function setFile(?string $file): static
    {
        $this->file = $file;
        return $this;
    }

    public function getFilename(): ?string
    {
        return $this->filename;
    }

    public function setFilename(?string $filename): static
    {
        $this->filename = $filename;
        return $this;
    }

    public function getFileType(): ?string
    {
        return $this->fileType;
    }

    public function setFileType(?string $fileType): static
    {
        $this->fileType = $fileType;
        return $this;
    }

    public function getSize(): ?string
    {
        return $this->size;
    }

    public function setSize(?string $size): static
    {
        $this->size = $size;
        return $this;
    }

    public function getFileSource(): ?string
    {
        return $this->fileSource;
    }

    public function setFileSource(?string $fileSource): static
    {
        $this->fileSource = $fileSource;
        return $this;
    }

    public function getDateStarted(): ?\DateTimeInterface
    {
        return $this->dateStarted;
    }

    public function setDateStarted(?\DateTimeInterface $dateStarted): static
    {
        $this->dateStarted = $dateStarted;
        return $this;
    }

    public function getDateFinished(): ?\DateTimeInterface
    {
        return $this->dateFinished;
    }

    public function setDateFinished(?\DateTimeInterface $dateFinished): static
    {
        $this->dateFinished = $dateFinished;
        return $this;
    }

    public function getImportResult(): ?string
    {
        return $this->importResult;
    }

    public function setImportResult(?string $importResult): static
    {
        $this->importResult = $importResult;
        return $this;
    }

    public function getRowsImported(): ?int
    {
        return $this->rowsImported;
    }

    public function setRowsImported(?int $rowsImported): static
    {
        $this->rowsImported = $rowsImported;
        return $this;
    }

    public function getInputParameters(): ?string
    {
        return $this->inputParameters;
    }

    public function setInputParameters(?string $inputParameters): static
    {
        $this->inputParameters = $inputParameters;
        return $this;
    }

    public function getType(): ?ImportManualType
    {
        return $this->type;
    }

    public function setType(?ImportManualType $type): static
    {
        $this->type = $type;
        return $this;
    }

    public function getStatus(): ?ImportManualStatus
    {
        return $this->status;
    }

    public function setStatus(?ImportManualStatus $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getUser(): ?User
    {
        return $this->user;
    }

    public function setUser(?User $user): static
    {
        $this->user = $user;
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

    public function getCreatedBy(): ?string
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?string $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getModifiedBy(): ?string
    {
        return $this->modifiedBy;
    }

    public function setModifiedBy(?string $modifiedBy): static
    {
        $this->modifiedBy = $modifiedBy;
        return $this;
    }
}
