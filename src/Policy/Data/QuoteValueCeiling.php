<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Above this net total the quote always escalates.
 *
 * The admin field is a plain number, which lands here under ANY_CURRENCY and
 * so applies to a quote in any currency — a shop selling in one currency wants
 * exactly that, and it is what the merchant typed.
 *
 * An ISO-keyed map is still accepted, set as JSON via `system:config:set`, for
 * a shop that really does need a different ceiling per currency. There a
 * currency left out is NOT unlimited: `netFor()` returns null and both call
 * sites escalate, because an unknown ceiling is exactly the case a human
 * should look at. No ceiling at all means `QuoteLimits::$valueCeiling` is null
 * instead, and then nothing is checked.
 */
final readonly class QuoteValueCeiling
{
    /**
     * A bare `maxQuoteValueNet` number means "this ceiling, whatever the
     * currency". That is what the admin's number field produces, and what the
     * ported TS fixtures already used.
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
     * The net/ISO pair to publish in the A2CN mandate, or null when there is
     * no honest one. `max_commitment_value` and `max_commitment_currency` are
     * scalars in the spec, so this has to resolve to exactly one pair.
     *
     * Given the currency the reader trades in — the storefront's own, since
     * the mandate is served per storefront — that currency answers it:
     * `netFor()` covers both a ceiling written for it and the bare
     * ANY_CURRENCY number the admin field produces. A currency with no
     * ceiling of its own still yields null, because `netFor()` returning null
     * is exactly the case both enforcement sites escalate rather than allow.
     *
     * Without a currency to anchor to, only an unambiguous single ISO-keyed
     * entry can speak: a bare ANY_CURRENCY number names no currency, and two
     * per-currency ceilings offer no way to choose.
     *
     * @return array{0: float, 1: string}|null
     */
    public function commitmentFor(?string $currencyIso): ?array
    {
        if ($currencyIso !== null) {
            $net = $this->netFor($currencyIso);

            return $net === null ? null : [$net, $currencyIso];
        }

        if (\count($this->netByCurrencyIso) !== 1) {
            return null;
        }

        $iso = array_key_first($this->netByCurrencyIso);

        return $iso === self::ANY_CURRENCY ? null : [$this->netByCurrencyIso[$iso], $iso];
    }
}
