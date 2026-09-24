<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Audit;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;

/**
 * Where a Draft Mode pass stands. Null on the row means the pass was never a
 * draft — every autonomous pass, and every row written before Draft Mode.
 */
enum ReviewStatus: string
{
    case Pending = 'pending';
    case Sent = 'sent';
    case Rejected = 'rejected';
    case Superseded = 'superseded';

    /** The outcomes that put something in front of the buyer — so, in Draft Mode, in front of the merchant. */
    public static function awaitsReview(NegotiationOutcome $outcome): bool
    {
        return \in_array(
            $outcome,
            [NegotiationOutcome::Offered, NegotiationOutcome::Countered, NegotiationOutcome::Clarified],
            strict: true,
        );
    }
}
