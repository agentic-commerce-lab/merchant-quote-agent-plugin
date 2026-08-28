<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

enum QuoteEscalationReason: string
{
    case DiscountLimitExceeded = 'discount_limit_exceeded';
    case QuoteValueLimitExceeded = 'quote_value_limit_exceeded';
    case NeedsHumanReview = 'needs_human_review';
    // New reason, not in the ported TS: the value-ceiling currency fix (issue #2b).
    case CurrencyMismatch = 'currency_mismatch';
    // Issue #5: the agent is enabled but cannot run — no API key, or config
    // that fails its own constraints.
    case NotConfigured = 'not_configured';
}
