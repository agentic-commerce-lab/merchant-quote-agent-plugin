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

        // Issue #169: this is the one call site where NeedsHumanReview is
        // literally true — the buyer asked for a person, or asked for
        // something the structural/non-price/unplaceable-ask cases above
        // don't cover. $interpretation->humanReviewRequests carries the
        // buyer's own words for it, which is what makes the specific claim
        // safe to show a merchant even though the enum value alone no longer
        // is (existing rows share it with eight other, unrelated causes).
        return QuoteDecision::escalate(new QuoteEscalationDetails(
            reason: QuoteEscalationReason::NeedsHumanReview,
            requestedDiscountPercent: MoneyMath::requestedDiscount($withoutBand),
            humanReviewRequests: $interpretation->humanReviewRequests,
        ));
    }
}
