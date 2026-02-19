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

use Gedmo\Mapping\Annotation as Gedmo;

#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['import_manual_status:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['import_manual_status:read']],
            denormalizationContext: ['groups' => ['import_manual_status:write']]
        ),
        new Get(
            normalizationContext: ['groups' => ['import_manual_status:read']]
        ),
        new Put(
            normalizationContext: ['groups' => ['import_manual_status:read']],
            denormalizationContext: ['groups' => ['import_manual_status:write']]
        ),
        new Patch(
            normalizationContext: ['groups' => ['import_manual_status:read']],
            denormalizationContext: ['groups' => ['import_manual_status:write']]
        ),
        new Delete()
    ],
    normalizationContext: ['groups' => ['import_manual_status:read']],
    denormalizationContext: ['groups' => ['import_manual_status:write']]
)]
#[ApiFilter(SearchFilter::class, properties: ['name' => 'partial'])]
#[ORM\Entity]
#[ORM\Table(name: 'import_manual_status_entity')]
class ImportManualStatus
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['import_manual_status:read', 'import_manual:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['import_manual_status:read', 'import_manual_status:write', 'import_manual:read'])]
    private ?string $name = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "create")]
    #[Groups(['import_manual_status:read'])]
    private ?\DateTimeInterface $createdAt = null;

    #[ORM\Column(type: "datetime")]
    #[Gedmo\Timestampable(on: "update")]
    #[Groups(['import_manual_status:read'])]
    private ?\DateTimeInterface $updatedAt = null;

    #[ORM\OneToMany(mappedBy: 'status', targetEntity: ImportManual::class)]
    private Collection $importManuals;

    public function __construct()
    {
        $this->importManuals = new ArrayCollection();
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

    public function setName(?string $name): static
    {
        $this->name = $name;
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
            $importManual->setStatus($this);
        }
        return $this;
    }

    public function removeImportManual(ImportManual $importManual): static
    {
        if ($this->importManuals->removeElement($importManual)) {
            if ($importManual->getStatus() === $this) {
                $importManual->setStatus(null);
            }
        }
        return $this;
    }
}
