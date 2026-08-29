<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/**
 * What one servicing pass did. The handler reads this to decide whether to
 * clear the escalation marker: an escalated quote must keep its marker, or it
 * re-escalates on every later buyer comment.
 */
enum NegotiationOutcome: string
{
    case Offered = 'offered';
    case Countered = 'countered';
    case Escalated = 'escalated';
    case NothingToDo = 'nothing_to_do';

    public function answeredTheBuyer(): bool
    {
        return $this === self::Offered || $this === self::Countered;
    }
}
