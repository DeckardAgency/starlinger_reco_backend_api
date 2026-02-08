<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\DepartmentRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
    ],
    normalizationContext: ['groups' => ['department:read']],
)]
#[ORM\Entity(repositoryClass: DepartmentRepository::class)]
#[ORM\Table(name: 'department_entity')]
class Department
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    #[Groups(['department:read', 'contact:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['department:read', 'contact:read'])]
    private ?string $name = null;

    // System fields
    #[ORM\Column(name: 'entity_type_id', type: 'smallint', options: ['unsigned' => true])]
    private int $entityTypeId = 1;

    #[ORM\Column(name: 'attribute_set_id', type: 'smallint', options: ['unsigned' => true])]
    private int $attributeSetId = 1;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $created = null;

    #[ORM\Column(type: 'datetime')]
    private ?\DateTimeInterface $modified = null;

    #[ORM\Column(name: 'entity_state_id', type: 'smallint', nullable: true, options: ['unsigned' => true])]
    private ?int $entityStateId = 1;

    public function getId(): ?int
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
}
