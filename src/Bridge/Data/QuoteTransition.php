<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data;

/** The quote state-machine actions an agent servicing pass drives. */
enum QuoteTransition: string
{
    case Process = 'process';
    case Sent = 'sent';
    case Decline = 'decline';
    case RequestChange = 'request_change';
}
