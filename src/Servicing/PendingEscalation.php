<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;

/**
 * Whether QuoteEscalator's marker still stands for an open escalation.
 *
 * The marker holds the reason, which cannot say whether a human has answered
 * since; the time written beside it can. Its own class because QuoteEscalator
 * is at the class complexity limit, and static in the idiom of
 * ClarificationMarker: a predicate over customFields.
 */
final class PendingEscalation
{
    /** When the marker was last written, as 'U.u' (MerchantHandover's format). */
    public const ESCALATED_AT_KEY = 'merchant_quote_agent_escalated_at';

    /** The state a merchant's "send" moves the quote into. */
    private const SENT_STATE = 'replied';

    private function __construct() {}

    /**
     * True while an escalation is still a human's to answer: the marker is
     * set and no merchant has SENT the quote since it was written.
     *
     * The agent releases the marker only when it answers itself
     * (QuoteEscalator::releaseFor()), so a human's answer leaves it standing.
     * Read alone, the marker would
     * keep a quote a human already answered silent for good, and the buyer's
     * "ok, thanks" would park it in `change_requested`, where over UCP they
     * can neither accept nor counter.
     *
     * Only a transition into `replied` counts. A merchant who moved the quote
     * anywhere else is still working on it, possibly with half-edited
     * prices, and moving it to `replied` for them would make
     * SellerActPublisher::recordApproval() receipt terms nobody sent. After a
     * send the terms are the human's, and that receipt is true. A tie stays
     * with the human.
     *
     * A marker written before its time was recorded cannot be ordered against
     * the send, so ANY send releases it. The lifecycle carries only the last
     * admin transition, so this has a known ceiling: a legacy quote a merchant
     * sent before it escalated is released too. That is still better than the
     * alternative. MerchantHandover stands the agent down while this is true,
     * so an undated marker that never released would keep the quote silent
     * for good.
     *
     * Known limit, the other way round: some sends never release it. A send
     * into `replied` with no admin user behind it (an integration or ERP
     * send) is invisible here, because MerchantActionReader only reads
     * history rows with a `user_id`. And the marker's time is this host's
     * clock while the send's is the DAL's `createdAt`, so skew between them
     * can leave a real send at or before `escalatedAt` under `<=`. Either way
     * the marker has to be cleared by hand. Widening the release to
     * author-less transitions is not the fix: the agent's own writes are
     * author-less too, and would release it.
     */
    public static function awaitsAHuman(QuoteLifecycle $lifecycle): bool
    {
        $reason = $lifecycle->customFields[QuoteEscalator::MARKER_KEY] ?? null;

        if (!\is_string($reason) || $reason === '') {
            return false;
        }

        $escalatedAt = $lifecycle->customFields[self::ESCALATED_AT_KEY] ?? null;
        $sentAt = $lifecycle->lastAdminTransitionTo === self::SENT_STATE
            ? $lifecycle->lastAdminTransitionAt?->format('U.u')
            : null;

        return $sentAt === null || \is_string($escalatedAt) && $sentAt <= $escalatedAt;
    }
}
