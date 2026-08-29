<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/**
 * The deterministic reply. Two jobs: it is the whole reply in rules-only mode,
 * and it is the fallback whenever the model's rewording drops a fact.
 *
 * It cannot hallucinate, which is why it is the safe side of that choice — a
 * plainer sentence reaching a buyer is strictly better than a fluent one with
 * the wrong number in it.
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
     */
    public static function reduction(float $beforeNet, float $afterNet): float
    {
        if ($beforeNet <= 0.0) {
            return 0.0;
        }

        return max(0.0, (($beforeNet - $afterNet) / $beforeNet) * 100.0);
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

    /** The prompt's own rule: a rewording must keep every figure exactly as given. */
    public static function keepsTheFacts(
        string $reworded,
        float $reductionPercent,
        float $totalNet,
        \DateTimeImmutable $validUntil,
    ): bool {
        return (
            $reworded !== ''
            && str_contains($reworded, self::percent($reductionPercent))
            && str_contains($reworded, self::money($totalNet))
            && str_contains($reworded, $validUntil->format('Y-m-d'))
        );
    }
}
