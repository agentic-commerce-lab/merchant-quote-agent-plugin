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
        // Not PositiveOrZero: Shopware-generated lines (e.g. the quote-discount
        // line) are legitimately negative — see LineNetViolation, which skips
        // the price-band check for exactly this reason.
        public float $unitPriceNet = 0.0,
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

    /** @throws \TypeError|\CuyZ\Valinor\Mapper\MappingError */
    public static function fromArray(array $data): self
    {
        return new self(
            identity: ArrayMapper::mapObject(QuoteLineIdentity::class, $data),
            quantity: OptionalShape::int($data, 'quantity') ?? 0,
            unitPriceNet: OptionalShape::float($data, 'unitPriceNet') ?? 0.0,
            totalNet: OptionalShape::float($data, 'totalNet') ?? 0.0,
            requestedUnitPrice: OptionalShape::float($data, 'requestedUnitPrice'),
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

    /**
     * This stored baseline row as the original of `$live` (#49, quote 1101):
     * the row is only a price and a quantity, so the identity the buyer sees
     * and the price they are asking for both come from the live line.
     */
    public function asOriginalOf(self $live): self
    {
        return new self(
            identity: $live->identity,
            quantity: $this->quantity,
            unitPriceNet: $this->unitPriceNet,
            totalNet: $this->totalNet,
            requestedUnitPrice: $live->requestedUnitPrice,
        );
    }
}
