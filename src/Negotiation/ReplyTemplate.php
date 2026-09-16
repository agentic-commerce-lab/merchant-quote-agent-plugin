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
 * `unsafeBecause()` is the other half of that sentence: it decides when the
 * fallback fires, and it is the only code between a model's free text and a
 * buyer.
 *
 * One sentence serves both concession shapes. A quote-wide discount and a
 * per-line price cut are the same thing to the buyer — the quote came down by
 * this much, to this total — and stating it that way is the only phrasing that
 * stays true when `discountPercent` is null, which is exactly what a per-line
 * offer carries.
 *
 * @mago-expect lint:cyclomatic-complexity
 * @mago-expect lint:kan-defect
 * Both rules aggregate per class. No single method here branches heavily --
 * `unsafeBecause()` is a flat sequence of independent early returns, one per
 * rule the guard enforces (empty, sentence cap, each concession word, then
 * the figure check) -- but four rules plus the two loops that check figures
 * in both directions add up across the class past the per-class threshold.
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

    /**
     * The prompt's own limit, enforced here so it is load-bearing rather than
     * advisory. Five, not tighter: code stricter than the prompt rejects a
     * model that did exactly as it was told, and the cost of that is not a
     * failure but a silent fallback to the plain template on every pass.
     */
    private const MAX_SENTENCES = 5;

    /**
     * Words that name a concession this system cannot make.
     *
     * AskGate escalates every non-price ask and OfferApplier writes price and
     * expiry only, so none of these can be honoured even in principle -- a
     * rewording that says one of them hands the buyer a promise in writing
     * that nothing downstream will ever act on.
     *
     * The omissions are deliberate. `free` matches "feel free to reach out",
     * which is ordinary merchant tone, and the concession it would guard
     * against is "free shipping", already caught by `shipping`. `net` reads as
     * "net total" far more often than as payment terms, and German "Netto"
     * names the very figure being quoted; `Net 30`/`Net 90` carry a digit and
     * are rejected as unauthorised figures instead. `terms` is the template's
     * own subject matter.
     *
     * This list is English. It is a backstop for the language the model is
     * overwhelmingly prompted in, not the boundary -- the boundary is the
     * figure check and the sentence cap, which are language-independent.
     */
    private const CONCESSIONS = [
        'shipping',
        'freight',
        'delivery',
        'payment',
        'invoice',
        'deposit',
        'warranty',
        'instalment',
        'installment',
    ];

    /**
     * Whether this rewording may reach the buyer, and if not, why.
     *
     * This is the last thing between a model's free text and a buyer: the
     * negotiate call's message is discarded and the escalation copy is a
     * constant, so nothing else the model writes is ever read on the other
     * side. It used to be three `str_contains()` calls, which asked only
     * whether the facts were STILL THERE and never whether anything had been
     * added -- so a rewording ending "...and we will also include free
     * shipping and Net 90 terms" passed intact (issue #53).
     *
     * @return string|null null when the rewording may ship
     */
    public static function unsafeBecause(
        string $reworded,
        float $reductionPercent,
        float $totalNet,
        \DateTimeImmutable $validUntil,
    ): ?string {
        if ($reworded === '') {
            return 'it is empty';
        }

        $sentences = (int) preg_match_all('#[.!?](?=\s|$)#u', $reworded);
        if ($sentences > self::MAX_SENTENCES) {
            return sprintf('it runs to %d sentences, past the %d it may use', $sentences, self::MAX_SENTENCES);
        }

        foreach (self::CONCESSIONS as $word) {
            if (preg_match('#\b' . $word . '\b#i', $reworded) === 1) {
                return 'it names a concession nobody authorised: ' . $word;
            }
        }

        return self::figuresAreWrong($reworded, $reductionPercent, $totalNet, $validUntil);
    }

    /**
     * The figures, checked in both directions in one pass over one token list.
     *
     * `#\d+(?:[.,:/-]\d+)*#` takes `2026-09-11`, `950.00` and `5` each as one
     * token and `Net 90` as `90`, which is what makes "an extra number" a
     * decidable question at all. Forwards: every token must be a figure the
     * template wrote. Backwards: every figure the template wrote must appear
     * as a token.
     *
     * The backwards direction is what replaces `str_contains()`, and it is
     * not a restatement of it. `str_contains($reworded, '5')` is satisfied by
     * `950.00`, so "We can bring this quote to 950.00 EUR, valid until
     * 2026-09-11." -- a reply that dropped the reduction entirely -- passed
     * the old guard. Every single-digit reduction has that shape, and so does
     * every 0% one, which is exactly what a per-line concession produces.
     */
    private static function figuresAreWrong(
        string $reworded,
        float $reductionPercent,
        float $totalNet,
        \DateTimeImmutable $validUntil,
    ): ?string {
        preg_match_all('#\d+(?:[.,:/-]\d+)*#', $reworded, $matches);
        /** @var list<string> $tokens */
        $tokens = $matches[0];

        $facts = [
            'the reduction percentage' => self::percent($reductionPercent),
            'the new total' => self::money($totalNet),
            'the validity date' => $validUntil->format('Y-m-d'),
        ];

        foreach ($tokens as $token) {
            if (!self::statedAmong($facts, $token)) {
                return 'it states a figure nobody authorised: ' . $token;
            }
        }

        foreach ($facts as $name => $fact) {
            if (!self::statedAmong($tokens, $fact)) {
                return 'it dropped ' . $name;
            }
        }

        return null;
    }

    /**
     * Whether any of $figures is the same figure as $subject.
     *
     * Symmetric on purpose: the same helper answers "is this token one of the
     * facts" and "is this fact one of the tokens", which is the whole of the
     * check above.
     *
     * The numeric branch absorbs exactly one measured drift and no more: a
     * model asked to keep `5` writes `5.00%`. Today `str_contains()` accepts
     * that by accident, and a string-exact rule would newly reject a rewording
     * that changed nothing. Comparing through `money()` -- which exists so
     * both sides agree on the string -- keeps it accepted without inventing a
     * tolerance rule. A date never reaches that branch: `2026-09-11` is not
     * numeric.
     *
     * @param iterable<string> $figures
     */
    private static function statedAmong(iterable $figures, string $subject): bool
    {
        foreach ($figures as $figure) {
            if ($figure === $subject) {
                return true;
            }

            if (
                is_numeric($figure)
                && is_numeric($subject)
                && self::money((float) $figure) === self::money((float) $subject)
            ) {
                return true;
            }
        }

        return false;
    }
}
