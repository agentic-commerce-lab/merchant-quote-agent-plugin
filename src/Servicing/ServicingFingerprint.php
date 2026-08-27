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
 * The three components, and why each is there:
 *
 * - **state** — a transition is work. Moves on our own writes too
 *   (open → in_review → replied), which is why the handler stamps a value
 *   recomputed from a FRESH read after servicing, not the value it compared.
 * - **authored comment count** — catches a buyer comment wherever in the
 *   servicing window it lands, including one older than the agent's own reply,
 *   which a "newest comment" marker alone would hide.
 * - **newest authored createdAt** — distinguishes an edited or replaced comment
 *   from an appended one at the same count.
 *
 * An agent comment is author-less on createdById, customerId and employeeId
 * alike (#3, pinned by AddCommentTest), so it is excluded from both comment
 * components and cannot move the fingerprint. The 42 pre-existing author-less
 * comments in the test shop are historical and static, so they cannot either.
 */
final class ServicingFingerprint
{
    public const MARKER_KEY = 'merchant_quote_agent_serviced';

    private function __construct() {}

    public static function of(QuoteSnapshot $snapshot): string
    {
        $authored = array_filter(
            $snapshot->content->comments,
            static fn(QuoteComment $comment): bool => $comment->isAuthored(),
        );

        return implode('|', [
            $snapshot->lifecycle->stateTechnicalName,
            (string) \count($authored),
            self::newestCreatedAt($authored),
        ]);
    }

    /** @param array<string, mixed> $customFields */
    public static function stamped(array $customFields): ?string
    {
        $stamped = $customFields[self::MARKER_KEY] ?? null;

        return \is_string($stamped) ? $stamped : null;
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
