<?php

namespace App\Message;

class OrderCreatedMessage
{
    private int $orderId;
    private ?\DateTimeInterface $createdAt;
    private ?string $messageId;
    private ?array $metadata;

    public function __construct(
        int $orderId,
        ?\DateTimeInterface $createdAt = null,
        ?array $metadata = null
    ) {
        $this->orderId = $orderId;
        $this->createdAt = $createdAt ?? new \DateTime();
        $this->messageId = uniqid('msg_', true);
        $this->metadata = $metadata;
    }

    public function getOrderId(): int
    {
        return $this->orderId;
    }

    public function getCreatedAt(): \DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getMessageId(): string
    {
        return $this->messageId;
    }

    public function getMetadata(): ?array
    {
        return $this->metadata;
    }

    public function setMetadata(?array $metadata): self
    {
        $this->metadata = $metadata;
        return $this;
    }

    public function addMetadata(string $key, mixed $value): self
    {
        if ($this->metadata === null) {
            $this->metadata = [];
        }
        $this->metadata[$key] = $value;
        return $this;
    }
}
