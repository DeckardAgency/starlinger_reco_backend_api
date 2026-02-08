<?php

namespace App\Entity;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use App\Repository\ContactTitleRepository;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Serializer\Annotation\Groups;

#[ApiResource(
    operations: [
        new GetCollection(),
        new Get(),
    ],
    normalizationContext: ['groups' => ['contact_title:read']],
)]
#[ORM\Entity(repositoryClass: ContactTitleRepository::class)]
#[ORM\Table(name: 'contact_title_entity')]
class ContactTitle
{
    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
    #[Groups(['contact_title:read', 'contact:read'])]
    private ?int $id = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['contact_title:read', 'contact:read'])]
    private ?string $name = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['contact_title:read'])]
    private ?string $uid = null;

    #[ORM\Column(name: 'is_custom', type: 'integer', nullable: true, options: ['unsigned' => true])]
    #[Groups(['contact_title:read'])]
    private ?int $isCustom = null;

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

    public function getUid(): ?string
    {
        return $this->uid;
    }

    public function setUid(?string $uid): static
    {
        $this->uid = $uid;
        return $this;
    }

    public function getIsCustom(): ?int
    {
        return $this->isCustom;
    }

    public function setIsCustom(?int $isCustom): static
    {
        $this->isCustom = $isCustom;
        return $this;
    }
}
