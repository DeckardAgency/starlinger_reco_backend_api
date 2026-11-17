<?php

namespace App\Service\Carrier\MyDhl\Dto;

/**
 * Response of POST /pickups.
 */
final class PickupResult
{
    /**
     * @param string[] $dispatchConfirmationNumbers
     * @param string[] $warnings
     */
    public function __construct(
        public readonly array $dispatchConfirmationNumbers,
        public readonly ?string $readyByTime,
        public readonly ?string $nextPickupDate,
        public readonly array $warnings,
    ) {
    }

    public static function fromArray(array $data): self
    {
        return new self(
            dispatchConfirmationNumbers: array_values(array_map('strval', $data['dispatchConfirmationNumbers'] ?? [])),
            readyByTime: $data['readyByTime'] ?? null,
            nextPickupDate: $data['nextPickupDate'] ?? null,
            warnings: array_values(array_map('strval', $data['warnings'] ?? [])),
        );
    }

    public function getConfirmationNumber(): ?string
    {
        return $this->dispatchConfirmationNumbers[0] ?? null;
    }
}
