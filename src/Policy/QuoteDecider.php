<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Port of `decideQuote` (the retired TS agent (policy/quote-decision.ts)). Pure price-band
 * engine: given the current snapshot and the merchant's limits, decide
 * auto-reply (with per-line prices) or escalate. Orchestrates the checks in
 * TS evaluation order: human review, then the value ceiling / discount band.
 */
final class QuoteDecider
{
    private readonly HumanReviewEscalation $humanReviewEscalation;

    private readonly QuoteDiscountApplier $discountApplier;

    private readonly QuoteBandDecider $bandDecider;

    public function __construct(
        ?HumanReviewEscalation $humanReviewEscalation = null,
        ?QuoteDiscountApplier $discountApplier = null,
        ?QuoteBandDecider $bandDecider = null,
    ) {
        $this->humanReviewEscalation = $humanReviewEscalation ?? new HumanReviewEscalation();
        $this->discountApplier = $discountApplier ?? new QuoteDiscountApplier();
        $this->bandDecider = $bandDecider ?? new QuoteBandDecider();
    }

    public function decide(
        QuoteSnapshot $snapshot,
        QuoteLimits $limits,
        ?CommentInterpretation $interpretation = null,
    ): QuoteDecision {
        $decision = $this->humanReviewEscalation->check($snapshot, $interpretation);

        if ($decision !== null) {
            return $decision;
        }

        $effective = $this->discountApplier->apply($snapshot, $interpretation, $limits);

        return $this->bandDecider->decide($effective, $limits);
    }
}
