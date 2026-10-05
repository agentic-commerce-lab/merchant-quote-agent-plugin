<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Servicing;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLifecycle;

/**
 * The merchant's newest comment, when it stands as an answer to an
 * escalation: written while the quote was `replied`, and not followed by an
 * admin transition. PendingEscalation::awaitsAHuman() documents why (QA-05).
 * Its own class because PendingEscalation is at the class complexity limit.
 */
final class RepliedComment
{
    private function __construct() {}

    /** When that comment was written, or null if there is none that stands. */
    public static function at(QuoteLifecycle $lifecycle): ?\DateTimeImmutable
    {
        $commentAt = $lifecycle->lastAdminCommentAt;
        $transitionAt = $lifecycle->lastAdminTransitionAt;

        if ($commentAt === null || $lifecycle->stateAtLastAdminComment !== PendingEscalation::SENT_STATE) {
            return null;
        }

        return $transitionAt === null || $transitionAt < $commentAt ? $commentAt : null;
    }
}
