<?php

namespace App\Entity;

use ApiPlatform\Doctrine\Orm\Filter\SearchFilter;
use ApiPlatform\Metadata\ApiFilter;
use ApiPlatform\Metadata\ApiProperty;
use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Delete;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\GetCollection;
use ApiPlatform\Metadata\Link;
use ApiPlatform\Metadata\Post;
use App\Repository\OrderInfoMessageRepository;
use DateTimeInterface;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Gedmo\Mapping\Annotation as Gedmo;
use Symfony\Component\Serializer\Annotation\Groups;
use Symfony\Component\Validator\Constraints as Assert;

#[ORM\Entity(repositoryClass: OrderInfoMessageRepository::class)]
#[ORM\Table(name: 'order_info_message')]
#[ORM\Index(name: 'idx_order_info_message_request', columns: ['info_request_id'])]
#[ORM\Index(name: 'idx_order_info_message_created_at', columns: ['created_at'])]
#[ApiResource(
    operations: [
        new Get(
            normalizationContext: ['groups' => ['order_info_message:read', 'media_item:read', 'user:read']]
        ),
        new GetCollection(
            paginationItemsPerPage: 50,
            normalizationContext: ['groups' => ['order_info_message:read', 'media_item:read']]
        ),
        new Post(
            securityPostDenormalize: "is_granted('OWN_ORDER', object)",
            normalizationContext: ['groups' => ['order_info_message:read', 'media_item:read']],
            denormalizationContext: ['groups' => ['order_info_message:write']]
        ),
        new Delete(security: "is_granted('ROLE_ADMIN')")
    ],
    normalizationContext: ['groups' => ['order_info_message:read']],
    denormalizationContext: ['groups' => ['order_info_message:write']]
)]
#[ApiResource(
    uriTemplate: '/order-info-requests/{infoRequestId}/messages',
    operations: [
        new GetCollection(
            normalizationContext: ['groups' => ['order_info_message:read', 'media_item:read', 'user:read']]
        ),
        new Post(
            securityPostDenormalize: "is_granted('OWN_ORDER', object)",
            normalizationContext: ['groups' => ['order_info_message:read', 'media_item:read']],
            denormalizationContext: ['groups' => ['order_info_message:write']]
        )
    ],
    uriVariables: [
        'infoRequestId' => new Link(
            fromProperty: 'messages',
            fromClass: OrderInfoRequest::class
        )
    ]
)]
#[ApiFilter(SearchFilter::class, properties: [
    'infoRequest.id' => 'exact',
    'senderType' => 'exact'
])]
class OrderInfoMessage
{
    public const SENDER_TYPE_ADMIN = 'admin';
    public const SENDER_TYPE_CLIENT = 'client';

    #[ORM\Id]
    #[ORM\GeneratedValue]
    #[ORM\Column(type: 'integer')]
    #[Groups(['order_info_message:read', 'order_info_request:read'])]
    private ?int $id = null;

    #[ORM\ManyToOne(targetEntity: OrderInfoRequest::class, inversedBy: 'messages')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    #[Groups(['order_info_message:read', 'order_info_message:write'])]
    #[ApiProperty(readableLink: false, writableLink: false)]
    #[Assert\NotNull]
    private ?OrderInfoRequest $infoRequest = null;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(nullable: false)]
    #[Groups(['order_info_message:read', 'order_info_request:read'])]
    #[ApiProperty(readableLink: true)]
    private ?User $sender = null;

    #[ORM\Column(length: 20)]
    #[Groups(['order_info_message:read', 'order_info_message:write', 'order_info_request:read', 'order_info_request:write'])]
    #[Assert\Choice(choices: [self::SENDER_TYPE_ADMIN, self::SENDER_TYPE_CLIENT])]
    private string $senderType = self::SENDER_TYPE_ADMIN;

    #[ORM\Column(type: 'text')]
    #[Groups(['order_info_message:read', 'order_info_message:write', 'order_info_request:read', 'order_info_request:write'])]
    #[Assert\NotBlank]
    #[Assert\Length(min: 1, max: 10000)]
    private ?string $messageText = null;

    #[ORM\Column(type: 'datetime')]
    #[Gedmo\Timestampable(on: 'create')]
    #[Groups(['order_info_message:read', 'order_info_request:read'])]
    private ?DateTimeInterface $createdAt = null;

    /**
     * @var Collection<int, MediaItem>
     */
    #[ORM\ManyToMany(targetEntity: MediaItem::class)]
    #[ORM\JoinTable(name: 'order_info_message_media_item')]
    #[Groups(['order_info_message:read', 'order_info_message:write', 'order_info_request:read', 'order_info_request:write'])]
    #[ApiProperty(readableLink: true, writableLink: true)]
    private Collection $mediaItems;

    public function __construct()
    {
        $this->mediaItems = new ArrayCollection();
    }

    public function getId(): ?int
    {
        return $this->id;
    }

    public function getInfoRequest(): ?OrderInfoRequest
    {
        return $this->infoRequest;
    }

    public function setInfoRequest(?OrderInfoRequest $infoRequest): static
    {
        $this->infoRequest = $infoRequest;
        return $this;
    }

    public function getSender(): ?User
    {
        return $this->sender;
    }

    public function setSender(?User $sender): static
    {
        $this->sender = $sender;
        return $this;
    }

    public function getSenderType(): string
    {
        return $this->senderType;
    }

    public function setSenderType(string $senderType): static
    {
        $this->senderType = $senderType;
        return $this;
    }

    public function getMessageText(): ?string
    {
        return $this->messageText;
    }

    public function setMessageText(string $messageText): static
    {
        $this->messageText = $messageText;
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

    /**
     * @return Collection<int, MediaItem>
     */
    public function getMediaItems(): Collection
    {
        return $this->mediaItems;
    }

    public function addMediaItem(MediaItem $mediaItem): static
    {
        if (!$this->mediaItems->contains($mediaItem)) {
            $this->mediaItems->add($mediaItem);
        }

        return $this;
    }

    public function removeMediaItem(MediaItem $mediaItem): static
    {
        $this->mediaItems->removeElement($mediaItem);

        return $this;
    }

    public function isFromAdmin(): bool
    {
        return $this->senderType === self::SENDER_TYPE_ADMIN;
    }

    public function isFromClient(): bool
    {
        return $this->senderType === self::SENDER_TYPE_CLIENT;
    }

    public function hasAttachments(): bool
    {
        return !$this->mediaItems->isEmpty();
    }

    #[Groups(['order_info_message:read', 'order_info_request:read'])]
    public function getAttachmentCount(): int
    {
        return $this->mediaItems->count();
    }

    public static function getValidSenderTypes(): array
    {
        return [
            self::SENDER_TYPE_ADMIN,
            self::SENDER_TYPE_CLIENT,
        ];
    }
}
