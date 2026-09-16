<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/**
 * The last thing between a model's free text and a buyer.
 *
 * The negotiate call's `modelMessage` is discarded and the escalation copy is
 * a constant, so `unsafeBecause()` is the only code in this system that ever
 * reads what a model wrote and lets it reach a buyer. That is also why it is
 * a separate class from `ReplyTemplate`: composing the safe fallback and
 * deciding whether to use it instead are two different jobs, and this one
 * carries all of the guard's branching.
 *
 * Payment and delivery are not this system's to promise: `AskGate` escalates
 * every non-price ask and `OfferApplier` writes price and expiry only. A term
 * the model writes into a reply for either one is a commitment nothing
 * downstream could honour even if it wanted to.
 */
final class RewordingGuard
{
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

    private function __construct() {}

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

        // array_filter() rather than a written-out foreach/if: the loop over
        // CONCESSIONS summed with the figure check below into a class the
        // linter's cyclomatic-complexity rule (threshold 10, aggregated
        // across the class) rejected, and every word is still checked against
        // the same regex.
        $found = array_filter(
            self::CONCESSIONS,
            static fn(string $word): bool => preg_match('#\b' . $word . '\b#i', $reworded) === 1,
        );
        if ($found !== []) {
            return 'it names a concession nobody authorised: ' . reset($found);
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
     *
     * Each direction is `array_filter()` against `statedAmong()` rather than a
     * written-out `foreach`/`if`: same first-failure semantics (array order
     * is preserved), one fewer pair of branches for the linter to sum.
     */
    private static function figuresAreWrong(
        string $reworded,
        float $reductionPercent,
        float $totalNet,
        \DateTimeImmutable $validUntil,
    ): ?string {
        $matches = [];
        preg_match_all('#\d+(?:[.,:/-]\d+)*#', $reworded, $matches);
        /** @var array{0: list<string>} $matches */
        $tokens = $matches[0];

        $facts = [
            'the reduction percentage' => ReplyTemplate::percent($reductionPercent),
            'the new total' => ReplyTemplate::money($totalNet),
            'the validity date' => $validUntil->format('Y-m-d'),
        ];

        $unauthorised = array_filter($tokens, static fn(string $found): bool => !self::statedAmong($facts, $found));
        if ($unauthorised !== []) {
            return 'it states a figure nobody authorised: ' . reset($unauthorised);
        }

        $dropped = array_filter($facts, static fn(string $fact): bool => !self::statedAmong($tokens, $fact));
        if ($dropped !== []) {
            return 'it dropped ' . array_key_first($dropped);
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
            if (
                $figure === $subject
                || is_numeric($figure)
                && is_numeric($subject)
                && ReplyTemplate::money((float) $figure) === ReplyTemplate::money((float) $subject)
            ) {
                return true;
            }
        }

        return false;
    }
}
