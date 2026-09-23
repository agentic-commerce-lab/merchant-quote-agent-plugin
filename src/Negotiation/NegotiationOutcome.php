<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/**
 * What one servicing pass did. The handler reads this to decide whether to
 * clear the escalation marker: an escalated quote must keep its marker, or it
 * re-escalates on every later buyer comment.
 * `Clarified` asked the buyer a question rather than answering them, so it
 * does not clear the escalation marker and does not count as a reply.
 * `HandedOver` is a pass that found a human merchant already on the quote and
 * wrote nothing. It does not answer the buyer, so `answeredTheBuyer()` stays
 * false for it and neither the escalation nor the clarification marker is
 * released -- deliberately: the merchant is already handling this quote, and
 * releasing either marker would let a later pass answer on top of them.
 * `Acknowledged` read a buyer comment that held no ask and answered it by
 * restating the quote as it stands, then moved it back to `replied` so the
 * buyer can accept or counter again. It is not an offer, so
 * `answeredTheBuyer()` stays false: it releases neither marker and carries
 * no figures for the admin's offer column.
 */
enum NegotiationOutcome: string
{
    case Offered = 'offered';
    case Countered = 'countered';
    case Escalated = 'escalated';
    case NothingToDo = 'nothing_to_do';
    case Clarified = 'clarified';
    case HandedOver = 'handed_over';
    case Acknowledged = 'acknowledged';

    public function answeredTheBuyer(): bool
    {
        return $this === self::Offered || $this === self::Countered;
    }
}
