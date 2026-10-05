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
     * set and no merchant has answered since it was written.
     *
     * The agent releases the marker only when it answers itself
     * (QuoteEscalator::releaseFor()), so a human's answer leaves it standing.
     * Read alone, the marker would
     * keep a quote a human already answered silent for good, and the buyer's
     * "ok, thanks" would park it in `change_requested`, where over UCP they
     * can neither accept nor counter.
     *
     * Two things count as an answer, and both rest on one invariant: when
     * the agent speaks again, the terms on the quote must be terms somebody
     * SENT. SellerActPublisher::recordApproval() receipts any changed terms
     * it emits while the marker stands, as a human's approval. The agent's
     * own new terms never get that receipt: emission runs in
     * ObserveQuoteHandler under the servicing lock, so after the pass, and
     * an offering pass has released the marker by then
     * (ServiceQuoteHandler's final write). A pass that releases nothing
     * (acknowledge, clarify) writes no terms, so whatever changed terms it
     * surfaces are the merchant's — which is why the merchant's terms must
     * be ones they sent.
     *
     *  - A transition into `replied`: a send. A merchant who moved the quote
     *    anywhere else is still working on it, possibly with half-edited
     *    prices, and moving it to `replied` for them would receipt terms
     *    nobody sent. After a send the terms are the human's, and that
     *    receipt is true.
     *  - A merchant comment, but ONLY while the quote sits in `replied`
     *    (QA-05). SwagCommercial's own "send" from `replied` saves the quote
     *    and posts the message without any transition, so it leaves no
     *    history row and the rule above never saw it; withdraw and resend
     *    was the merchant's only way out. In `replied` the terms on the quote
     *    are the ones the buyer was sent, so the receipt invariant holds. In
     *    any other state a comment may be a note written mid-edit, and the
     *    agent stays down until a send.
     *
     * A tie stays with the human, for both.
     *
     * A marker written before its time was recorded cannot be ordered against
     * the answer, so ANY answer releases it. The lifecycle carries only the
     * last admin transition and the last admin comment, so this has a known
     * ceiling: a legacy quote a merchant answered before it escalated is
     * released too. That is still better than the alternative.
     * MerchantHandover stands the agent down while this is true, so an
     * undated marker that never released would keep the quote silent for
     * good.
     *
     * Known limit, the other way round: some answers never release it. A send
     * into `replied` with no admin user behind it (an integration or ERP
     * send) is invisible here, because MerchantActionReader only reads
     * history rows with a `user_id`, and an integration's comment carries no
     * `createdById` either. And the marker's time is this host's clock while
     * the answer's is the DAL's `createdAt`, so skew between them can leave a
     * real answer at or before `escalatedAt` under `<=`. Either way the
     * marker has to be cleared by hand. Widening the release to author-less
     * writes is not the fix: the agent's own writes are author-less too, and
     * would release it.
     */
    public static function awaitsAHuman(QuoteLifecycle $lifecycle): bool
    {
        $reason = $lifecycle->customFields[QuoteEscalator::MARKER_KEY] ?? null;

        if (!\is_string($reason) || $reason === '') {
            return false;
        }

        $escalatedAt = $lifecycle->customFields[self::ESCALATED_AT_KEY] ?? null;
        $answers = array_filter([
            $lifecycle->lastAdminTransitionTo === self::SENT_STATE ? $lifecycle->lastAdminTransitionAt : null,
            $lifecycle->stateTechnicalName === self::SENT_STATE ? $lifecycle->lastAdminCommentAt : null,
        ]);

        if ($answers === []) {
            return true;
        }

        return \is_string($escalatedAt) && max($answers)->format('U.u') <= $escalatedAt;
    }
}
