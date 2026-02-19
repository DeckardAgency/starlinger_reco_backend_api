<?php

namespace App\Message;

class UserInvitedMessage
{
    private int $invitationId;

    public function __construct(int $invitationId)
    {
        $this->invitationId = $invitationId;
    }

    public function getInvitationId(): int
    {
        return $this->invitationId;
    }
}
