<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

enum QuoteDecisionKind: string
{
    case AutoReply = 'auto_reply';
    case Escalate = 'escalate';
}
