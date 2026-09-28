<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

enum QuoteEscalationReason: string
{
    case DiscountLimitExceeded = 'discount_limit_exceeded';
    case QuoteValueLimitExceeded = 'quote_value_limit_exceeded';
    // Issue #169: kept deliberately narrow now that the other eight
    // NeedsHumanReview call sites have their own case. Two things still carry
    // it going forward: HumanReviewEscalation, where the buyer genuinely asked
    // for a person, and a handful of "should be unreachable" defensive
    // fallbacks (an invariant the type system cannot express, or a
    // should-never-fire safety guard) whose true cause is, by construction,
    // not knowable here either. Every row written before this issue also
    // carries this value for one of the nine conflated reasons the snippet
    // used to name as if it were always the first of those. The snippet for
    // this case must stay honest for BOTH: it may not claim "the customer
    // asked for a human" as if that were provable from the value alone.
    case NeedsHumanReview = 'needs_human_review';
    // The quote's currency has no configured value ceiling. An unknown
    // ceiling is not an unlimited one, so a human decides.
    case CurrencyMismatch = 'currency_mismatch';
    // Issue #5: the agent is enabled but cannot run — no API key, or config
    // that fails its own constraints.
    case NotConfigured = 'not_configured';
    // Issue #18. The model could not be reached or answered unusably; the
    // model itself declined; or the database disagreed with what we applied.
    case ModelUnavailable = 'model_unavailable';
    case ProposalRejected = 'proposal_rejected';
    case VerificationFailed = 'verification_failed';
    // Issue #169. The buyer asked to change what is being sold — quantity,
    // removing a line, adding a product — rather than its price. AskGate.
    case StructuralChangeRequested = 'structural_change_requested';
    // Issue #169. The buyer asked for a delivery or payment term. Nothing
    // downstream of AskGate can grant or write one. AskGate.
    case NonPriceTermRequested = 'non_price_term_requested';
    // Issue #169. The model could not place the buyer's ask on a line, and
    // the buyer was already asked once to clarify it. ClarificationRound.
    case UnplaceableAsk = 'unplaceable_ask';
    // A price ask whose pass wrote no concession: the model held, the cap or
    // the minimum-margin floor left nothing to give, or an earlier round
    // already gave it. Never answered with 0% (spec
    // 2026-09-24-never-answer-zero-discount). OfferRound.
    case NoFurtherConcession = 'no_further_concession';
    // Draft Mode. Never recorded on a decision row: it names the NOTICE that a
    // draft is waiting for the merchant, sent through the same channels as an
    // escalation so the seeded escalation flow covers it without a new event.
    case DraftReady = 'draft_ready';
}
