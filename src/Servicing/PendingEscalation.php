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
     * can neither accept nor counter (quote 1056).
     *
     * Only a transition into `replied` counts. A merchant who moved the quote
     * anywhere else is still working on it, possibly with half-edited
     * prices, and moving it to `replied` for them would make
     * SellerActPublisher::recordApproval() receipt terms nobody sent. After a
     * send the terms are the human's, and that receipt is true. A tie, and a
     * marker written before the time was recorded, stay with the human.
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

        return !\is_string($escalatedAt) || $sentAt === null || $sentAt <= $escalatedAt;
    }
}
