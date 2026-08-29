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
    // Issue #18. The model could not be reached or answered unusably; the
    // model itself declined; or the database disagreed with what we applied.
    case ModelUnavailable = 'model_unavailable';
    case ProposalRejected = 'proposal_rejected';
    case VerificationFailed = 'verification_failed';
}
