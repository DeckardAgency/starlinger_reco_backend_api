<?php

namespace App\Message;

class OrderInfoRequestMessage
{
    public function __construct(
        private int $infoRequestId,
        private string $action,
        private ?string $newStatus = null
    ) {
    }

    public function getInfoRequestId(): int
    {
        return $this->infoRequestId;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getNewStatus(): ?string
    {
        return $this->newStatus;
    }
}
