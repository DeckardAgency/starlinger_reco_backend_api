<?php

namespace App\Message;

/**
 * Dispatched when an admin creates a user account directly (with a password),
 * so the user gets a welcome email telling them the account exists.
 * (Invitation-based signups get the invitation email instead.)
 */
class UserAccountCreatedMessage
{
    public function __construct(
        private readonly int $userId,
    ) {
    }

    public function getUserId(): int
    {
        return $this->userId;
    }
}
