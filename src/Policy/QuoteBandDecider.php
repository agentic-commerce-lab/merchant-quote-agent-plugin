<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteDecision;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationDetails;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteEscalationReason;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Given the effective (post-discount-ask) snapshot, decide whether it clears
 * the value ceiling and discount band, or escalate.
 */
final class QuoteBandDecider
{
    private const float EPSILON = 1e-6;

    private readonly QuoteAutoReplyPricer $pricer;

    public function __construct(?QuoteAutoReplyPricer $pricer = null)
    {
        $this->pricer = $pricer ?? new QuoteAutoReplyPricer();
    }

    public function decide(QuoteSnapshot $effective, QuoteLimits $limits): QuoteDecision
    {
        $ceiling = $limits->valueCeiling;
        if ($ceiling !== null && $effective->totalNet > ($ceiling->net + self::EPSILON)) {
            return QuoteDecision::escalate(new QuoteEscalationDetails(
                reason: QuoteEscalationReason::QuoteValueLimitExceeded,
                requestedDiscountPercent: MoneyMath::requestedDiscount($effective),
            ));
        }

        $discountPercent = max(0.0, MoneyMath::requestedDiscount($effective) ?? 0.0);
        if ($discountPercent > ($limits->maxDiscountPercent + self::EPSILON)) {
            return QuoteDecision::escalate(new QuoteEscalationDetails(
                reason: QuoteEscalationReason::DiscountLimitExceeded,
                requestedDiscountPercent: $discountPercent,
            ));
        }

        return QuoteDecision::autoReply($this->pricer->price($effective, $discountPercent, $limits->validityDays));
    }
}
