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
    private readonly QuoteAutoReplyPricer $pricer;

    public function __construct(?QuoteAutoReplyPricer $pricer = null)
    {
        $this->pricer = $pricer ?? new QuoteAutoReplyPricer();
    }

    public function decide(QuoteSnapshot $effective, QuoteLimits $limits): QuoteDecision
    {
        $ceiling = $limits->valueCeiling;

        if ($ceiling !== null) {
            $ceilingNet = $ceiling->netFor($effective->currencyIso);

            // No entry for this currency escalates rather than passing: the
            // merchant set ceilings and did not set one here, so the limit is
            // unknown, and treating unknown as unlimited is how a 500k quote
            // gets auto-answered in a currency nobody configured.
            if ($ceilingNet === null) {
                return QuoteDecision::escalate(new QuoteEscalationDetails(
                    reason: QuoteEscalationReason::CurrencyMismatch,
                    requestedDiscountPercent: MoneyMath::requestedDiscount($effective),
                ));
            }

            if ($effective->totalNet > ($ceilingNet + Epsilon::RATE)) {
                return QuoteDecision::escalate(new QuoteEscalationDetails(
                    reason: QuoteEscalationReason::QuoteValueLimitExceeded,
                    requestedDiscountPercent: MoneyMath::requestedDiscount($effective),
                ));
            }
        }

        $discountPercent = max(0.0, MoneyMath::requestedDiscount($effective) ?? 0.0);
        $counterCeiling = $limits->counterOfferMaxPercent;

        if ($discountPercent > ($limits->maxDiscountPercent + Epsilon::RATE)) {
            // The counter band, configured by #5: an ask the agent may not grant
            // outright but may answer with a fixed counter at its own cap. Above
            // the ceiling — or with no ceiling set — the pre-#18 behaviour stands
            // and a human decides.
            if ($counterCeiling === null || $discountPercent > ($counterCeiling + Epsilon::RATE)) {
                return QuoteDecision::escalate(new QuoteEscalationDetails(
                    reason: QuoteEscalationReason::DiscountLimitExceeded,
                    requestedDiscountPercent: $discountPercent,
                ));
            }

            return QuoteDecision::autoReply($this->pricer->price(
                $effective,
                $limits->maxDiscountPercent,
                $limits->validityDays,
                counteredRequestPercent: $discountPercent,
            ));
        }

        return QuoteDecision::autoReply($this->pricer->price($effective, $discountPercent, $limits->validityDays));
    }
}
