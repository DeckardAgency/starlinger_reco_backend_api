<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\DateFilter;
use ApiPlatform\Doctrine\Orm\Filter\OrderFilter;
use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Patch;
use ApiPlatform\Metadata\Post;
use App\Repository\OrderInfoRequestRepository;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrderInfoRequestRepository::class)]
#[ORM\Table(name: 'order_info_request')]
#[ORM\Index(name: 'idx_order_info_request_order', columns: ['order_id'])]
#[ORM\Index(name: 'idx_order_info_request_item', columns: ['order_item_id'])]
#[ORM\Index(name: 'idx_order_info_request_status', columns: ['status'])]
#[ApiResource(
    operations: [
        new Get(
            normalizationContext: ['groups' => ['order_info_request:read', 'order_info_message:read', 'media_item:read', 'user:read']]
        ),
        new GetCollection(
            paginationItemsPerPage: 30,
            normalizationContext: ['groups' => ['order_info_request:read', 'order_info_message:read', 'media_item:read']]
        ),
        new Post(
            normalizationContext: ['groups' => ['order_info_request:read', 'order_info_message:read', 'media_item:read']],
            denormalizationContext: ['groups' => ['order_info_request:write']],
            security: "is_granted('ROLE_ADMIN')"
        ),
        new Patch(
            security: "is_granted('OWN_ORDER', object)",
            securityPostDenormalize: "is_granted('OWN_ORDER', object)",
            normalizationContext: ['groups' => ['order_info_request:read', 'order_info_message:read', 'media_item:read']],
            denormalizationContext: ['groups' => ['order_info_request:update']]
        ),
        new Delete(security: "is_granted('ROLE_ADMIN')")
    ],
    normalizationContext: ['groups' => ['order_info_request:read']],
    denormalizationContext: ['groups' => ['order_info_request:write']]
)]
#[ApiResource(
    uriTemplate: '/orders/{orderId}/info-requests',
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['order_info_request:read', 'order_info_message:read', 'media_item:read']]
        )
    ],
    uriVariables: [
        'orderId' => new Link(
            fromProperty: 'infoRequests',
            fromClass: Order::class
        )
    ]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'status' => 'exact',
    'order.orderNumber' => 'partial',
    'orderItem.id' => 'exact'
])]
#[ApiFilter(DateFilter::class, properties: ['createdAt', 'updatedAt'])]
#[ApiFilter(OrderFilter::class, properties: ['createdAt', 'updatedAt', 'status'], arguments: ['orderParameterName' => 'order'])]
class OrderInfoRequest
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_RESPONDED = 'responded';
    public const STATUS_ACCEPTED = 'accepted';
    public const STATUS_NEEDS_REVISION = 'needs_revision';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['order_info_request:read', 'order:read', 'order_item:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: Order::class, inversedBy: 'infoRequests')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['order_info_request:read', 'order_info_request:write'])]
    #[ApiProperty(readableLink: false, writableLink: false)]
    #[Assert\NotNull]
    private ?Order $order = null;

    #[ORM\ManyToOne(targetEntity: OrderItem::class, inversedBy: 'infoRequests')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['order_info_request:read', 'order_info_request:write'])]
    #[ApiProperty(readableLink: true, writableLink: false)]
    #[Assert\NotNull]
    private ?OrderItem $orderItem = null;

    #[ORM\Column(length: 50)]
    #[Groups(['order_info_request:read', 'order_info_request:update', 'order:read', 'order_item:read'])]
    private string $status = self::STATUS_PENDING;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['order_info_request:read'])]
    #[ApiProperty(readableLink: true)]
    private ?User $createdBy = null;

    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'create')]
    #[Groups(['order_info_request:read', 'order:read', 'order_item:read'])]
    private ?DateTimeInterface $createdAt = null;

    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'update')]
    #[Groups(['order_info_request:read'])]
    private ?DateTimeInterface $updatedAt = null;

    /**
     * @var Collection<int, OrderInfoMessage>
     */
    #[ORM\OneToMany(
        targetEntity: OrderInfoMessage::class,
        mappedBy: 'infoRequest',
        cascade: ['persist', 'remove'],
        orphanRemoval: true
    )]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    #[Groups(['order_info_request:read', 'order_info_request:write'])]
    #[ApiProperty(readableLink: true, writableLink: true)]
    private Collection $messages;

    public function __construct()
    {
        $this->messages = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getOrder(): ?Order
    {
        return $this->order;
    }

    public function setOrder(?Order $order): static
    {
        $this->order = $order;
        return $this;
    }

    public function getOrderItem(): ?OrderItem
    {
        return $this->orderItem;
    }

    public function setOrderItem(?OrderItem $orderItem): static
    {
        $this->orderItem = $orderItem;

        // Also set the order from the item for convenience
        if ($orderItem !== null && $orderItem->getOrderRef() !== null) {
            $this->order = $orderItem->getOrderRef();
        }

        return $this;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): static
    {
        $this->status = $status;
        return $this;
    }

    public function getCreatedBy(): ?User
    {
        return $this->createdBy;
    }

    public function setCreatedBy(?User $createdBy): static
    {
        $this->createdBy = $createdBy;
        return $this;
    }

    public function getCreatedAt(): ?DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): static
    {
        $this->createdAt = $createdAt;
        return $this;
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt): static
    {
        $this->updatedAt = $updatedAt;
        return $this;
    }

    /**
     * @return Collection<int, OrderInfoMessage>
     */
    public function getMessages(): Collection
    {
        return $this->messages;
    }

    public function addMessage(OrderInfoMessage $message): static
    {
        if (!$this->messages->contains($message)) {
            $this->messages->add($message);
            $message->setInfoRequest($this);
        }

        return $this;
    }

    public function removeMessage(OrderInfoMessage $message): static
    {
        if ($this->messages->removeElement($message)) {
            if ($message->getInfoRequest() === $this) {
                $message->setInfoRequest(null);
            }
        }

        return $this;
    }

    public function getLatestMessage(): ?OrderInfoMessage
    {
        if ($this->messages->isEmpty()) {
            return null;
        }

        $messages = $this->messages->toArray();
        usort($messages, fn($a, $b) => $b->getCreatedAt() <=> $a->getCreatedAt());

        return $messages[0];
    }

    #[Groups(['order_info_request:read', 'order:read', 'order_item:read'])]
    public function getMessageCount(): int
    {
        return $this->messages->count();
    }

    public function hasResponse(): bool
    {
        foreach ($this->messages as $message) {
            if ($message->getSenderType() === OrderInfoMessage::SENDER_TYPE_CLIENT) {
                return true;
            }
        }
        return false;
    }

    public function markAsResponded(): static
    {
        if ($this->status === self::STATUS_PENDING || $this->status === self::STATUS_NEEDS_REVISION) {
            $this->status = self::STATUS_RESPONDED;
        }
        return $this;
    }

    public function markAsAccepted(): static
    {
        $this->status = self::STATUS_ACCEPTED;
        return $this;
    }

    public function markAsNeedsRevision(): static
    {
        $this->status = self::STATUS_NEEDS_REVISION;
        return $this;
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING || $this->status === self::STATUS_NEEDS_REVISION;
    }

    public function isAccepted(): bool
    {
        return $this->status === self::STATUS_ACCEPTED;
    }

    public static function getValidStatuses(): array
    {
        return [
            self::STATUS_PENDING,
            self::STATUS_RESPONDED,
            self::STATUS_ACCEPTED,
            self::STATUS_NEEDS_REVISION,
        ];
    }
}
