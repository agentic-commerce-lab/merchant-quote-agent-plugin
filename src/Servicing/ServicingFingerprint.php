<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteComment;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;

/**
 * A fingerprint of what the agent last serviced, persisted on the quote's
 * customFields. Comparing it against a fresh read is what makes a duplicate
 * trigger a no-op and a real buyer ask real work.
 *
 * Deliberately NOT the quote's revision. Only `updatedAt` moves (#3) and our
 * own servicing moves it — line prices, discount, expiration, state, and the
 * marker write itself — so a revision marker differs from itself on the next
 * pass and every duplicate trigger looks like new work. Revision-abort also
 * discards a buyer comment that lands mid-pass, which is a dropped ask rather
 * than a suppressed duplicate.
 *
 * The four components, and why each is there:
 *
 * - **state** — a transition is work. Moves on our own writes too
 *   (open → in_review → replied), which is why the handler stamps a value
 *   recomputed from a FRESH read after servicing, not the value it compared.
 * - **buyer comment count** — catches a buyer comment wherever in the
 *   servicing window it lands, including one older than the agent's own reply,
 *   which a "newest comment" marker alone would hide. The BUYER's comments
 *   only: see buyerAuthored().
 * - **newest buyer createdAt** — distinguishes an edited or replaced comment
 *   from an appended one at the same count.
 * - **per-line requested prices** — the one ask that arrives without a comment,
 *   so none of the three above move when the buyer edits it. Appended rather
 *   than joined unconditionally, and only when there is an ask at all, so that
 *   every marker written before it existed stays valid.
 *
 * Two of the three writers are excluded, for different reasons. An agent
 * comment is author-less on createdById, customerId and employeeId alike (#3,
 * pinned by AddCommentTest), so our own reply cannot move the marker and
 * re-trigger us forever. A merchant's note carries createdById alone and is
 * excluded because it is not an ask — see #55; the fingerprint is what makes
 * the trigger's own author filter a cost saving rather than the fix. The 42
 * pre-existing author-less comments in the test shop are historical and
 * static, so they cannot move it either.
 *
 * Both exclusions only ever REMOVE components from the composed string, never
 * add or reorder, so two reads of the same quote still compose the same
 * string. What they do change is the string a quote composed BEFORE this
 * deploy: a quote carrying a merchant comment differs from its own stamp once
 * and buys exactly one pass, the same one-off the asks component accepted.
 * That pass is a no-op by construction — with the conversation split fixed
 * there is no new buyer ask, so the pipeline records nothing_to_do without a
 * model call.
 */
final class ServicingFingerprint
{
    public const MARKER_KEY = 'merchant_quote_agent_serviced';

    private function __construct() {}

    public static function of(QuoteSnapshot $snapshot): string
    {
        return self::compose(
            $snapshot->lifecycle->stateTechnicalName,
            self::buyerAuthored($snapshot),
            self::asks($snapshot),
        );
    }

    /**
     * The value to persist after a successful pass: the comment components of
     * the snapshot we actually SERVICED, with the state as it stands afterwards.
     *
     * Not `of($after)`. The post-servicing read may already contain a buyer
     * comment that arrived DURING the pass — LLM latency is seconds — and
     * stamping it would claim credit for input this pass never saw. That
     * comment's own message would then compute an identical fingerprint and
     * return, silently dropping a real ask. It is the same failure mode that
     * disqualified a revision marker, arriving by a different route.
     *
     * The state must come from the fresh read because our own transition moves
     * it (open → in_review → replied); stamping the serviced snapshot's state
     * would leave the quote looking permanently unserviced.
     */
    public static function stamp(QuoteSnapshot $serviced, string $stateAfter): string
    {
        return self::compose($stateAfter, self::buyerAuthored($serviced), self::asks($serviced));
    }

    /** @param array<string, mixed> $customFields */
    public static function stamped(array $customFields): ?string
    {
        $stamped = $customFields[self::MARKER_KEY] ?? null;

        return \is_string($stamped) ? $stamped : null;
    }

    /**
     * Only the BUYER's comments. A merchant's own note carries `createdById`
     * and neither buyer column, and it is not work anyone is waiting on:
     * counting it made a merchant's aside look like a new ask, which bought a
     * pass that then answered the aside (#55).
     *
     * @return array<int, QuoteComment>
     */
    private static function buyerAuthored(QuoteSnapshot $snapshot): array
    {
        return array_filter(
            $snapshot->content->comments,
            static fn(QuoteComment $comment): bool => $comment->isBuyerAuthored(),
        );
    }

    /**
     * The structured component is APPENDED, and only when there is one.
     *
     * Every marker already written omits it, so a quote with no per-line ask
     * has to compose the exact string it composed before this component
     * existed — otherwise deploying it makes every quote in the shop differ
     * from its own stamp and buys one pass each, and the ones still carrying
     * an unanswered ask get answered a second time.
     *
     * @param array<int, QuoteComment> $authored
     */
    private static function compose(string $state, array $authored, string $asks): string
    {
        $marker = implode('|', [$state, (string) \count($authored), self::newestCreatedAt($authored)]);

        return $asks === '' ? $marker : $marker . '|' . $asks;
    }

    /**
     * The buyer's per-line targets, the one ask that arrives without a comment:
     * SwagCommercial writes it to `quote_line_item.requested_price` and the
     * storefront offers it beside the quoted price, so editing it moves neither
     * the state nor either comment component. Without this a buyer who changes
     * their number and types nothing is told nothing has happened.
     *
     * Keyed on the ask and not on whether it is still unmet: the agent's own
     * write moves `unitPriceNet` down to meet it, so a met-only filter would
     * drop the component exactly when the pass succeeds, differ from itself and
     * buy one pointless pass per answered quote.
     *
     * Sorted, because line order is a read-model detail; and formatted to a
     * fixed two decimals for `newestCreatedAt()`'s reason — a marker compared
     * as a string must not depend on how a float prints.
     */
    private static function asks(QuoteSnapshot $snapshot): string
    {
        $asks = [];

        foreach ($snapshot->content->lines as $line) {
            if ($line->requestedUnitPrice !== null) {
                $asks[] = $line->identity->lineItemId . ':' . number_format($line->requestedUnitPrice, 2, '.', '');
            }
        }

        sort($asks);

        return implode(',', $asks);
    }

    /**
     * Compared and returned as a zero-padded 'U.u' string rather than a float:
     * the column is datetime(3) and a float comparison at microsecond scale is
     * exactly the kind of rounding this marker must not have. Both parts are
     * fixed-width, so string ordering is chronological ordering.
     *
     * @param array<int, QuoteComment> $comments
     */
    private static function newestCreatedAt(array $comments): string
    {
        $newest = '0';

        foreach ($comments as $comment) {
            $createdAt = $comment->createdAt?->format('U.u');

            if ($createdAt !== null && ($newest === '0' || $createdAt > $newest)) {
                $newest = $createdAt;
            }
        }

        return $newest;
    }
}
