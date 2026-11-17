<?php

namespace App\Service\Carrier\MyDhl\Dto;

/**
 * Response of POST /shipments: the AWB, per-piece tracking numbers and the
 * generated documents (label, and for dutiable shipments the commercial invoice).
 */
final class ShipmentResult
{
    /**
     * @param string[]               $pieceTrackingNumbers
     * @param array<string, string>  $documents typeCode ("label", "invoice", "waybillDoc", ...) => raw PDF bytes
     * @param string[]               $warnings
     */
    public function __construct(
        public readonly string $awb,
        public readonly array $pieceTrackingNumbers,
        public readonly array $documents,
        public readonly ?string $dispatchConfirmationNumber,
        public readonly ?\DateTimeImmutable $estimatedDeliveryAt,
        public readonly ?float $price,
        public readonly ?string $currency,
        public readonly array $warnings,
    ) {
    }

    public static function fromArray(array $data): self
    {
        $documents = [];
        foreach ($data['documents'] ?? [] as $document) {
            $type = $document['typeCode'] ?? 'label';
            $bytes = base64_decode((string) ($document['content'] ?? ''), true);
            if ($bytes !== false && $bytes !== '') {
                // Multi-piece shipments can return one label document per call; keep the first per type
                $documents[$type] ??= $bytes;
            }
        }

        $price = null;
        $currency = null;
        foreach ($data['shipmentCharges'] ?? [] as $charge) {
            if (($charge['currencyType'] ?? null) === 'BILLC') {
                $price = isset($charge['price']) ? (float) $charge['price'] : null;
                $currency = $charge['priceCurrency'] ?? null;
                break;
            }
        }

        $estimated = $data['estimatedDeliveryDate']['estimatedDeliveryDate'] ?? null;

        return new self(
            awb: (string) ($data['shipmentTrackingNumber'] ?? ''),
            pieceTrackingNumbers: array_values(array_filter(array_map(
                static fn (array $p) => $p['trackingNumber'] ?? null,
                $data['packages'] ?? []
            ))),
            documents: $documents,
            dispatchConfirmationNumber: $data['dispatchConfirmationNumber'] ?? null,
            estimatedDeliveryAt: $estimated ? new \DateTimeImmutable($estimated) : null,
            price: $price,
            currency: $currency,
            warnings: array_values(array_map('strval', $data['warnings'] ?? [])),
        );
    }

    public function getLabel(): ?string
    {
        return $this->documents['label'] ?? null;
    }
}
