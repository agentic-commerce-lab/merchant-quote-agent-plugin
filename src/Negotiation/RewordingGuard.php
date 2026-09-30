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
 * The reply prompt also carries the buyer's own newest
 * comment, so the model it guards is now reading untrusted text
 * (`ReplyComposer::userMessage()`). Nothing here was relaxed for it, and
 * nothing should be: a figure the buyer wrote is a figure nobody authorised,
 * which is what makes "acknowledge what they asked for" safe to ask for at
 * all. The reply prompt holds no history either, so an instruction smuggled
 * into that comment has nothing to disclose even if the model obeys it.
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
     * own subject matter. `invoice` names a document rather than a term --
     * "your invoice will show the new total" promises nothing this system
     * cannot do, so rejecting it would cost a rewording and buy no safety.
     *
     * This list is English. It is a backstop for the language the model is
     * overwhelmingly prompted in, not the boundary -- the boundary is the
     * figure check and the sentence cap, which are language-independent.
     *
     * The match allows an optional plural suffix (`(?:e?s)?` in
     * `unsafeBecause()`), because a bare `\b` after the literal word was not
     * the boundary it looked like. A trailing `s` is itself a word character,
     * so `\bpayment\b` never matches inside "payments" -- there is no
     * boundary between `t` and `s` for `\b` to find. All three of
     * "installments", "payments" and "deposits" reached the buyer verbatim
     * under the old check, and the plural is often the more natural phrasing
     * in the first place, which made it the likelier miss, not the rarer one.
     *
     * `deliveries` and `warranties` are listed as their own entries rather
     * than relying on the suffix rule, because `(?:e?s)?` only ever appends
     * -- it cannot turn a trailing `y` into `ies`. `delivery` plus that
     * suffix matches "deliverys", which is not a word, and never matches
     * "deliveries".
     *
     * Stemming to `deliver` and `warrant` was measured and rejected instead
     * of listing the plurals separately: it does catch every plural, but
     * `warrant` also fires on "We hope this warrants your approval", which is
     * ordinary merchant tone and not a concession at all. Listing the plural
     * forms explicitly catches the same concessions without that false
     * rejection.
     */
    private const CONCESSIONS = [
        'shipping',
        'shipment',
        'freight',
        'delivery',
        'deliveries',
        'payment',
        'deposit',
        'warranty',
        'warranties',
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
     * @param ?float $reductionPercent null for a pass that granted nothing
     *                                 (#175): the reply then states no
     *                                 reduction figure at all, so any number
     *                                 that reads as one is unauthorised.
     *
     * @return string|null null when the rewording may ship
     */
    public static function unsafeBecause(
        string $reworded,
        ?float $reductionPercent,
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
            static fn(string $word): bool => preg_match('#\b' . $word . '(?:e?s)?\b#i', $reworded) === 1,
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
     *
     * One hole survives on purpose. A membership check cannot tell an invented
     * figure from an authorised one when the two are numerically equal: with a
     * 5% reduction, "Order 5 more units and we will do better" passes, because
     * `5` is the reduction percentage. Counting occurrences instead of
     * membership would close it -- and would also reject a faithful rewording
     * that simply restates a figure, which the accepting test table already
     * pins ('the same figure restated'). The window this leaves open is
     * narrow: the invented number must equal the reduction percentage or the
     * total to two decimal places. What escapes is always a quantity, never a
     * term -- the concession list above and the sentence cap still apply to
     * the sentence around it.
     */
    private static function figuresAreWrong(
        string $reworded,
        ?float $reductionPercent,
        float $totalNet,
        \DateTimeImmutable $validUntil,
    ): ?string {
        $matches = [];
        preg_match_all('#\d+(?:[.,:/-]\d+)*#', $reworded, $matches);
        /** @var array{0: list<string>} $matches */
        $tokens = $matches[0];

        // #175: null means this pass granted nothing, so `holds()` never
        // wrote a reduction figure -- omitted here rather than defaulted to
        // `0`, or a model inventing "0%" for a hold would read as the same
        // figure the template already stated instead of one nobody
        // authorised. array_filter()/array_map() rather than a ternary: a
        // ternary is itself a branch, and this class is already at the
        // linter's complexity ceiling (see CONCESSIONS' array_filter() above).
        $reductionFact = array_filter(
            ['the reduction percentage' => $reductionPercent],
            static fn(?float $value): bool => $value !== null,
        );
        $facts = array_map(ReplyTemplate::percent(...), $reductionFact)
        + [
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
        $subject = self::ungrouped($subject);

        foreach ($figures as $figure) {
            $figure = self::ungrouped($figure);
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

    /**
     * A grouped thousands separator removed, and nothing else touched.
     *
     * `money()` writes `9500.00` deliberately, but a model handed that figure
     * writes `9,500.00` back -- that is how money is spelled, not a figure
     * nobody authorised. Without this the guard rejected every rewording of
     * every quote of 1000 or more, which is most B2B quotes, and rejected it
     * the invisible way: template ships, nothing fails, rewording is simply
     * off. The old `str_contains()` check fell back identically, so this is a
     * hole being closed rather than a regression being fixed.
     *
     * The pattern is narrow on purpose, because a comma is a DECIMAL point in
     * half of this plugin's market. `^\d{1,3}(?:,\d{3})+` demands at least
     * one full group of exactly three digits, which `1,5` and `1,50` cannot
     * satisfy -- those reach the comparison untouched and fail it, exactly as
     * they do today. Only the unambiguous grouped spelling is normalised.
     *
     * German `9.500,00` is NOT handled. Its decimal comma makes it
     * unambiguous too, but its grouping dot is not: `9.500` is nine and a half
     * to the same regex that has to read `950.00` as a total. That is a
     * locale decision, not a formatting one, and it is written up as a risk
     * rather than guessed at here.
     *
     * `preg_replace_callback()` rather than a match-then-replace `if`, for the
     * same reason `array_filter()` is used above: the branch pushed this class
     * past the linter's cyclomatic-complexity threshold, which is aggregated
     * across the class. The pattern stays anchored either way -- a bare
     * `preg_replace()` of grouping commas would drop the anchor with it.
     *
     * Applied to both sides of the comparison, so the grouped form answers
     * "is this token authorised" and "did this fact survive" alike -- the
     * second matters as much as the first, since a total the guard cannot
     * recognise reads as a total the model dropped.
     */
    private static function ungrouped(string $figure): string
    {
        return (string) preg_replace_callback(
            '#^\d{1,3}(?:,\d{3})+(?:\.\d+)?$#',
            static fn(array $grouped): string => str_replace(',', '', (string) $grouped[0]),
            $figure,
        );
    }
}
