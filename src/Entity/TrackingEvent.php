<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Post;
use App\Enum\TrackingStatus;
use App\Repository\TrackingEventRepository;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: TrackingEventRepository::class)]
#[ORM\Table(name: 'tracking_event')]
#[ORM\Index(name: 'idx_tracking_event_order', columns: ['order_ref_id'])]
#[ORM\Index(name: 'idx_tracking_event_status', columns: ['status'])]
#[ApiResource(
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['tracking_event:read']]
        ),
        new Get(
            normalizationContext: ['groups' => ['tracking_event:read']]
        ),
        new Post(
            security: "is_granted('ROLE_ADMIN')",
            normalizationContext: ['groups' => ['tracking_event:read']],
            denormalizationContext: ['groups' => ['tracking_event:write']]
        ),
        new Delete(security: "is_granted('ROLE_ADMIN')")
    ],
    normalizationContext: ['groups' => ['tracking_event:read']],
    denormalizationContext: ['groups' => ['tracking_event:write']],
    order: ['occurredAt' => 'DESC']
)]
#[ApiFilter(SearchFilter::class, properties: [
    'orderRef' => 'exact',
    'orderRef.id' => 'exact',
    'status' => 'exact',
    'source' => 'exact',
])]
#[ApiFilter(OrderFilter::class, properties: ['occurredAt', 'recordedAt'])]
class TrackingEvent
{
    public const SOURCE_MANUAL = 'manual';
    public const SOURCE_DHL_API = 'dhl_api';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['tracking_event:read', 'order:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'trackingEvents')]
    #[ORM\JoinColumn(name: 'order_ref_id', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    #[Assert\NotNull]
    #[Groups(['tracking_event:read', 'tracking_event:write'])]
    private ?Order $orderRef = null;

    #[ORM\Column(length: 50)]
    #[Assert\NotBlank]
    #[Assert\Choice(callback: [TrackingStatus::class, 'cases'])]
    #[Groups(['tracking_event:read', 'tracking_event:write', 'order:read'])]
    private ?string $status = null;

    #[ORM\Column(type: 'text', nullable: true)]
    #[Groups(['tracking_event:read', 'tracking_event:write', 'order:read'])]
    private ?string $description = null;

    #[ORM\Column(length: 255, nullable: true)]
    #[Groups(['tracking_event:read', 'tracking_event:write', 'order:read'])]
    private ?string $location = null;

    #[ORM\Column(type: 'datetime')]
    #[Assert\NotNull]
    #[Groups(['tracking_event:read', 'tracking_event:write', 'order:read'])]
    private ?\DateTimeInterface $occurredAt = null;

    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'create')]
    #[Groups(['tracking_event:read', 'order:read'])]
    private ?\DateTimeInterface $recordedAt = null;

    #[ORM\Column(length: 20)]
    #[Assert\Choice(choices: [self::SOURCE_MANUAL, self::SOURCE_DHL_API])]
    #[Groups(['tracking_event:read', 'tracking_event:write', 'order:read'])]
    private string $source = self::SOURCE_MANUAL;

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrderRef(): ?Order
    {
        return $this->orderRef;
    }

    public function setOrderRef(?Order $orderRef): static
    {
        $this->orderRef = $orderRef;
        return $this;
    }

    public function getStatus(): ?string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
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

    public function getLocation(): ?string
    {
        return $this->location;
    }

    public function setLocation(?string $location): static
    {
        $this->location = $location;
        return $this;
    }

    public function getOccurredAt(): ?\DateTimeInterface
    {
        return $this->occurredAt;
    }

    public function setOccurredAt(\DateTimeInterface $occurredAt): static
    {
        $this->occurredAt = $occurredAt;
        return $this;
    }

    public function getRecordedAt(): ?\DateTimeInterface
    {
        return $this->recordedAt;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function setSource(string $source): static
    {
        $this->source = $source;
        return $this;
    }
}
