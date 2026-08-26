<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * ponytail: `calculatedPrice` from the TS contract is dropped — it only feeds
 * A2CN net-normalization, which belongs to the later Protocol module.
 */
final readonly class QuoteLineSnapshot
{
    public function __construct(
        public QuoteLineIdentity $identity,
        #[Assert\PositiveOrZero]
        public int $quantity = 0,
        #[Assert\PositiveOrZero]
        public float $unitPriceNet = 0.0,
        #[Assert\PositiveOrZero]
        public float $totalNet = 0.0,
        #[Assert\PositiveOrZero]
        public ?float $requestedUnitPrice = null,
    ) {}

    public function lineItemId(): string
    {
        return $this->identity->lineItemId;
    }

    public function label(): ?string
    {
        return $this->identity->label;
    }

    /** @throws \TypeError|\ValueError */
    public static function fromArray(array $data): self
    {
        return new self(
            identity: QuoteLineIdentity::fromArray($data),
            quantity: OptionalShape::int($data, 'quantity') ?? 0,
            unitPriceNet: OptionalShape::float($data, 'unitPriceNet') ?? 0.0,
            totalNet: OptionalShape::float($data, 'totalNet') ?? 0.0,
            requestedUnitPrice: OptionalShape::float($data, 'requestedUnitPrice'),
        );
    }

    public function withUnitPriceNet(float $unitPriceNet): self
    {
        return new self(
            identity: $this->identity,
            quantity: $this->quantity,
            unitPriceNet: $unitPriceNet,
            totalNet: $this->totalNet,
            requestedUnitPrice: $this->requestedUnitPrice,
        );
    }

    public function withRequestedUnitPrice(?float $requestedUnitPrice): self
    {
        return new self(
            identity: $this->identity,
            quantity: $this->quantity,
            unitPriceNet: $this->unitPriceNet,
            totalNet: $this->totalNet,
            requestedUnitPrice: $requestedUnitPrice,
        );
    }
}
