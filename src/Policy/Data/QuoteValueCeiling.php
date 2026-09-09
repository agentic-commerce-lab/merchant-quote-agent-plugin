<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Above this net total the quote always escalates — one ceiling per currency,
 * because a number without a currency is not a limit.
 *
 * The merchant sets it in the admin as a Shopware price field, so the currency
 * symbol is shown next to the box and "Maintain currency prices" fills in the
 * rest. QuoteAgentSettingsReader resolves each entry's currency id to its ISO
 * code before the policy is built, so nothing below the reader has to know
 * that a currency has a uuid.
 *
 * A currency the merchant left blank is NOT unlimited: `netFor()` returns null
 * and both call sites escalate, because an unknown ceiling is exactly the case
 * a human should look at. No ceiling anywhere means `QuoteLimits::$valueCeiling`
 * is null instead, and then nothing is checked at all.
 */
final readonly class QuoteValueCeiling
{
    /**
     * The reserved key the ported TS fixtures use: a bare `maxQuoteValueNet`
     * number means "this ceiling, whatever the currency". Real configuration
     * never produces it — the price field always names a currency — so it is
     * the fixture shorthand and the pre-price-field behaviour, in one key.
     */
    public const ANY_CURRENCY = '*';

    /** @param array<string, float> $netByCurrencyIso keyed by ISO 4217 code, or by ANY_CURRENCY */
    public function __construct(
        #[Assert\All([new Assert\PositiveOrZero()])]
        #[Assert\Count(min: 1)]
        public array $netByCurrencyIso,
    ) {}

    /** Null means no ceiling is configured for this currency, which is not the same as no ceiling. */
    public function netFor(string $currencyIso): ?float
    {
        return $this->netByCurrencyIso[$currencyIso] ?? $this->netByCurrencyIso[self::ANY_CURRENCY] ?? null;
    }

    /**
     * The single net/ISO pair to publish in the A2CN mandate, or null when
     * there is no unambiguous one. `max_commitment_value` and
     * `max_commitment_currency` are scalars in the spec, so a shop with two
     * different per-currency ceilings has no honest answer and publishes
     * neither.
     *
     * @return array{0: float, 1: string}|null
     */
    public function soleCommitment(): ?array
    {
        if (\count($this->netByCurrencyIso) !== 1) {
            return null;
        }

        $iso = array_key_first($this->netByCurrencyIso);

        return $iso === self::ANY_CURRENCY ? null : [$this->netByCurrencyIso[$iso], $iso];
    }
}
