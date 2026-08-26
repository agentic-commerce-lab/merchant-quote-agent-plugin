<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\CommentInterpretation;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationDetails;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Free-text asks the agent cannot fulfill by discounting always go to a
 * human, regardless of limits — silently ignoring them would misrepresent
 * the offer.
 */
final class HumanReviewEscalation
{
    private readonly QuoteDiscountApplier $discountApplier;

    public function __construct(?QuoteDiscountApplier $discountApplier = null)
    {
        $this->discountApplier = $discountApplier ?? new QuoteDiscountApplier();
    }

    public function check(QuoteSnapshot $snapshot, ?CommentInterpretation $interpretation): ?QuoteDecision
    {
        if ($interpretation === null || $interpretation->humanReviewRequests === []) {
            return null;
        }

        $withoutBand = $this->discountApplier->apply($snapshot, $interpretation);

        return QuoteDecision::escalate(new QuoteEscalationDetails(
            reason: QuoteEscalationReason::NeedsHumanReview,
            requestedDiscountPercent: MoneyMath::requestedDiscount($withoutBand),
            humanReviewRequests: $interpretation->humanReviewRequests,
        ));
    }
}
