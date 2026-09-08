<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

enum QuoteEscalationReason: string
{
    case DiscountLimitExceeded = 'discount_limit_exceeded';
    case QuoteValueLimitExceeded = 'quote_value_limit_exceeded';
    case NeedsHumanReview = 'needs_human_review';
    // `currency_mismatch` used to live here, written when the value ceiling
    // carried its own ISO code. Nothing writes it since that field left the
    // admin, but rows on disk still hold the string, so the administration
    // snippet for it stays.
    // Issue #5: the agent is enabled but cannot run — no API key, or config
    // that fails its own constraints.
    case NotConfigured = 'not_configured';
    // Issue #18. The model could not be reached or answered unusably; the
    // model itself declined; or the database disagreed with what we applied.
    case ModelUnavailable = 'model_unavailable';
    case ProposalRejected = 'proposal_rejected';
    case VerificationFailed = 'verification_failed';
}
