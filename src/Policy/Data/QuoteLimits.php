<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class QuoteLimits
{
    /**
     * @mago-expect lint:excessive-parameter-list
     * Seven merchant settings, each named at the one production construction
     * site (fromArray()) and by name in every fixture; the two rounding
     * fields are one setting's two halves, and a sub-object for two scalars
     * would add a validated class for nothing.
     */
    public function __construct(
        #[Assert\Range(min: 0, max: 100)]
        public float $maxDiscountPercent,
        #[Assert\Range(min: 0, max: 100)]
        public ?float $counterOfferMaxPercent = null,
        #[Assert\Valid]
        public ?QuoteValueCeiling $valueCeiling = null,
        /**
         * At least one day.
         *
         * `0` is what every path meaning "nobody set this" produces: an absent
         * key in fromArray() below, a cleared admin field in
         * NegotiationPolicyArray::build(), and this default. Keeping all three
         * at `0` and rejecting it here is deliberate — substituting a number
         * would be the plugin inventing a validity on the merchant's behalf.
         *
         * #57: this was PositiveOrZero and config.xml shipped `0`, so
         * OfferApplier wrote `+0 days` — an expiry of *now* — and
         * ExpirationOfferVerifier's one-sided window had nothing to say about
         * it. Every auto-offer on an untouched install went out already
         * expired, and the buyer reply said "valid until <today>".
         *
         * The default stays `0` rather than becoming `14`: no production code
         * reaches it (fromArray() is the only production constructor, and
         * NegotiationPolicyArray always supplies the key), so changing it
         * would only hide this constraint from the tests that construct
         * QuoteLimits directly.
         */
        #[Assert\Positive]
        public int $validityDays = 0,
        /**
         * Markup on the purchase price below which no offer may price a line
         * (spec 2026-09-24). Null means off. Zero means "never below cost".
         * No upper bound: a markup can exceed 100%.
         */
        #[Assert\PositiveOrZero]
        public ?float $minMarginPercent = null,
        /**
         * Rounding control (spec 2026-09-28). Off unless the merchant picks a
         * mode AND a step above zero — see stepFor().
         */
        public RoundingMode $roundingMode = RoundingMode::Off,
        /** Percentage points in discount_percent mode; currency units of the buyer-facing total in quote_total mode. */
        #[Assert\PositiveOrZero]
        public ?float $roundingStep = null,
    ) {}

    /** The step `$mode` rounds to; see RoundingMode::stepUnder(). */
    public function stepFor(RoundingMode $mode): ?float
    {
        return $mode->stepUnder($this->roundingMode, $this->roundingStep);
    }

    /** The same limits with a tightened discount cap — see AskedDiscountCeiling. */
    public function withMaxDiscountPercent(float $maxDiscountPercent): self
    {
        return new self(
            maxDiscountPercent: $maxDiscountPercent,
            counterOfferMaxPercent: $this->counterOfferMaxPercent,
            valueCeiling: $this->valueCeiling,
            validityDays: $this->validityDays,
            minMarginPercent: $this->minMarginPercent,
            roundingMode: $this->roundingMode,
            roundingStep: $this->roundingStep,
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
            minMarginPercent: OptionalShape::float($data, 'minMarginPercent'),
            // from(), not tryFrom(): a mode nothing knows must refuse the
            // channel (ValueError -> InvalidQuoteAgentConfiguration), not
            // quietly read as off.
            roundingMode: RoundingMode::from(OptionalShape::string($data, 'roundingMode') ?? RoundingMode::Off->value),
            roundingStep: OptionalShape::float($data, 'roundingStep'),
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
