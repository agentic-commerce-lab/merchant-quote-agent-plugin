<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentAsk;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentDecision;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentPolicy;

/**
 * Port of `decidePayment` in src/policy/negotiate-dimensions.ts.
 */
final class PaymentDecider
{
    private readonly BandAggregator $bandAggregator;

    public function __construct(?BandAggregator $bandAggregator = null)
    {
        $this->bandAggregator = $bandAggregator ?? new BandAggregator();
    }

    public function decide(PaymentAsk $ask, ?PaymentPolicy $policy): PaymentDecision
    {
        if ($policy === null) {
            return new PaymentDecision(band: Band::Escalate, reason: 'no payment policy configured');
        }

        $outcomes = array_values(array_filter([
            $ask->requestedTerm !== null ? PaymentTermDecider::decide($ask->requestedTerm, $policy) : null,
            $ask->requestedNetDays !== null ? NetDaysDecider::decide($ask->requestedNetDays, $policy) : null,
            $ask->requestedDepositPercent !== null
                ? DepositDecider::decide($ask->requestedDepositPercent, $policy)
                : null,
        ]));

        return PaymentDecisionMerger::merge($outcomes, $this->bandAggregator);
    }
}
