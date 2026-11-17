<?php

namespace App\Service\Carrier\MyDhl;

/**
 * A MyDHL API call failed. The message is human-readable (DHL's `detail` plus its
 * `additionalDetails`, e.g. "5002: The Pickup time window ... is too small") so it
 * can be shown to the admin as-is.
 */
class MyDhlException extends \RuntimeException
{
    /**
     * @param string[] $details DHL's additionalDetails lines
     */
    public function __construct(
        string $message,
        private readonly ?int $httpStatus = null,
        private readonly array $details = [],
        private readonly ?string $messageReference = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public function getHttpStatus(): ?int
    {
        return $this->httpStatus;
    }

    /** @return string[] */
    public function getDetails(): array
    {
        return $this->details;
    }

    /** The Message-Reference header sent with the request — quote it to DHL support. */
    public function getMessageReference(): ?string
    {
        return $this->messageReference;
    }
}
