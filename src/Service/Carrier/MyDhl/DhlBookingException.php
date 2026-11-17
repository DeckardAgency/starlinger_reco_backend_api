<?php

namespace App\Service\Carrier\MyDhl;

/**
 * A booking request RECO refuses before calling DHL: invalid/missing input
 * (422, with one message per problem) or a conflicting order state (409).
 */
class DhlBookingException extends \RuntimeException
{
    /**
     * @param string[] $errors
     */
    public function __construct(
        string $message,
        private readonly array $errors = [],
        private readonly int $httpStatus = 422,
    ) {
        parent::__construct($message);
    }

    /** @param string[] $errors */
    public static function invalid(array $errors): self
    {
        return new self('The shipment data is incomplete or invalid.', $errors, 422);
    }

    public static function conflict(string $message): self
    {
        return new self($message, [], 409);
    }

    /** @return string[] */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function getHttpStatus(): int
    {
        return $this->httpStatus;
    }
}
