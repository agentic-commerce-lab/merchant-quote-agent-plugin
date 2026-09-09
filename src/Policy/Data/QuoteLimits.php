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
        return new self(
            maxDiscountPercent: RequiredShape::float($data, 'maxDiscountPercent'),
            counterOfferMaxPercent: OptionalShape::float($data, 'counterOfferMaxPercent'),
            valueCeiling: self::ceiling($data),
            validityDays: OptionalShape::int($data, 'validityDays') ?? 0,
        );
    }

    /**
     * `maxQuoteValueNet` is either a bare number (the admin's field) that reads
     * as a ceiling for any currency, or a currency-keyed map for a shop that
     * sets one per ISO code via `system:config:set --json`.
     *
     * @throws \TypeError|\ValueError
     */
    private static function ceiling(array $data): ?QuoteValueCeiling
    {
        $raw = $data['maxQuoteValueNet'] ?? null;

        if (\is_array($raw)) {
            $byIso = [];

            foreach (array_keys($raw) as $iso) {
                $byIso[(string) $iso] = RequiredShape::float($raw, (string) $iso);
            }

            return $byIso === [] ? null : new QuoteValueCeiling($byIso);
        }

        $net = OptionalShape::float($data, 'maxQuoteValueNet');

        return $net === null ? null : new QuoteValueCeiling([QuoteValueCeiling::ANY_CURRENCY => $net]);
    }
}
