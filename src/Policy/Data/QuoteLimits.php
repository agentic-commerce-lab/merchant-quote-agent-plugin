<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class QuoteLimits
{
    public function __construct(
        #[Assert\Range(min: 0, max: 100)]
        public float $maxDiscountPercent,
        #[Assert\Range(min: 0, max: 100)]
        public ?float $counterOfferMaxPercent = null,
        #[Assert\Valid]
        public ?QuoteValueCeiling $valueCeiling = null,
        #[Assert\PositiveOrZero]
        public int $validityDays = 0,
    ) {}

    /** The same limits with a tightened discount cap — see AskedDiscountCeiling. */
    public function withMaxDiscountPercent(float $maxDiscountPercent): self
    {
        return new self(
            maxDiscountPercent: $maxDiscountPercent,
            counterOfferMaxPercent: $this->counterOfferMaxPercent,
            valueCeiling: $this->valueCeiling,
            validityDays: $this->validityDays,
        );
    }

    /** @throws \TypeError|\ValueError */
    public static function fromArray(array $data): self
    {
        $ceilingNet = OptionalShape::float($data, 'maxQuoteValueNet');

        return new self(
            maxDiscountPercent: RequiredShape::float($data, 'maxDiscountPercent'),
            counterOfferMaxPercent: OptionalShape::float($data, 'counterOfferMaxPercent'),
            valueCeiling: $ceilingNet === null ? null : new QuoteValueCeiling(net: $ceilingNet),
            validityDays: OptionalShape::int($data, 'validityDays') ?? 0,
        );
    }
}
