<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Policy\Epsilon;

/**
 * The deterministic reply. Two jobs: it is the whole reply in rules-only mode,
 * and it is the fallback whenever the model's rewording drops a fact.
 *
 * It cannot hallucinate, which is why it is the safe side of that choice — a
 * plainer sentence reaching a buyer is strictly better than a fluent one with
 * the wrong number in it.
 *
 * `RewordingGuard` is the other half of that sentence: this class writes the
 * fallback, that class decides when the fallback fires.
 *
 * One sentence serves both concession shapes. A quote-wide discount and a
 * per-line price cut are the same thing to the buyer — the quote came down by
 * this much, to this total — and stating it that way is the only phrasing that
 * stays true when `discountPercent` is null, which is exactly what a per-line
 * offer carries.
 */
final class ReplyTemplate
{
    private function __construct() {}

    public static function compose(
        float $reductionPercent,
        float $totalNet,
        string $currencyIso,
        \DateTimeImmutable $validUntil,
    ): string {
        return sprintf(
            'We can bring this quote down by %s%% to %s %s. The offer is valid until %s.',
            self::percent($reductionPercent),
            self::money($totalNet),
            $currencyIso,
            $validUntil->format('Y-m-d'),
        );
    }

    /**
     * What the quote actually came down by, measured on the totals the
     * DATABASE reports before and after the write — never on the offer we
     * asked for. The two agree for a quote-wide discount and are the only
     * available figure for a per-line one.
     *
     * #174: no longer clamps a negative movement to `0.0`. That clamp is how
     * two buyers were told "a 5% reduction" and "a 2% reduction" on quotes
     * their write had just made MORE expensive -- the arithmetic was
     * negative, and `max(0.0, …)` turned it into a cheerful zero instead of
     * the error it was. `OfferApplier`'s never-raise check DETECTS a write
     * that lands above `$beforeNet` (it cannot prevent one; there is no
     * rollback), so `$afterNet > $beforeNet` reaching this function means
     * that detection itself missed one. This function throws rather than
     * describing an increase as a reduction -- `OfferRound` catches the
     * throw at the call site and escalates the pass instead of composing a
     * reply, so the exception never reaches a customer-facing sentence or a
     * retrying worker (see `NegativeReduction`'s own docblock).
     */
    public static function reduction(float $beforeNet, float $afterNet): float
    {
        if ($beforeNet <= 0.0) {
            return 0.0;
        }

        if ($afterNet > ($beforeNet + Epsilon::MONEY)) {
            throw new NegativeReduction(sprintf(
                'the total rose from %.2f to %.2f; the write-time check should have caught this '
                . 'and escalated the pass before a reply was ever composed for it',
                $beforeNet,
                $afterNet,
            ));
        }

        return (($beforeNet - $afterNet) / $beforeNet) * 100.0;
    }

    /**
     * #175: the sentence for a real write too small to print at two
     * decimals -- 0.50 EUR off a 34456.73 quote reads as `0`. `compose()`
     * announcing "down by 0%" implied a concession the buyer cannot see,
     * twice, in the same session that produced #174. A pass that wrote
     * nothing never reaches here: `PostWriteOutcome` escalates it as
     * `no_further_concession`. No percentage in it at all, so
     * `RewordingGuard` has no reduction figure to compare a hallucinated one
     * against -- only the total and the date.
     */
    public static function holds(float $totalNet, string $currencyIso, \DateTimeImmutable $validUntil): string
    {
        return sprintf(
            'This quote stands at %s %s. The offer remains valid until %s.',
            self::money($totalNet),
            $currencyIso,
            $validUntil->format('Y-m-d'),
        );
    }

    /**
     * The answer to a comment that held no ask: the quote as it stands, and
     * the two things the buyer can do with it. Without a reply the quote never
     * leaves `change_requested`, and over UCP a buyer can neither accept nor
     * counter from there (live quote 1056).
     *
     * The validity sentence is dropped rather than invented when the quote has
     * no expiry: this restates the quote, it does not add a term to it.
     */
    public static function acknowledges(float $total, string $currencyIso, ?\DateTimeImmutable $validUntil): string
    {
        $validity = $validUntil === null
            ? ''
            : sprintf(' The offer remains valid until %s.', $validUntil->format('Y-m-d'));

        return sprintf(
            'Thank you for your message. This quote stands at %s %s.%s '
            . 'You can accept it as it is, or tell us what you would like changed.',
            self::money($total),
            $currencyIso,
            $validity,
        );
    }

    /** The figure the guard looks for, formatted once so both sides agree. */
    public static function percent(float $reductionPercent): string
    {
        return rtrim(rtrim(number_format($reductionPercent, 2, '.', ''), '0'), '.');
    }

    /** No thousands separator: the guard matches the string the template wrote. */
    public static function money(float $totalNet): string
    {
        return sprintf('%.2f', $totalNet);
    }
}
