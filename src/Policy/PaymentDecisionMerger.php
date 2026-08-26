<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentDecision;

/**
 * Merge independent term/net-days/deposit outcomes into one PaymentDecision.
 * Ported from `mergeOutcomes` in src/policy/negotiate-dimensions.ts.
 */
final class PaymentDecisionMerger
{
    /** @param list<PaymentDecision> $outcomes */
    public static function merge(array $outcomes, BandAggregator $bandAggregator): PaymentDecision
    {
        $reasons = [];
        $grantedTerm = null;
        $grantedNetDays = null;
        $grantedDepositPercent = null;

        foreach ($outcomes as $outcome) {
            $grantedTerm ??= $outcome->grantedTerm;
            $grantedNetDays ??= $outcome->grantedNetDays;
            $grantedDepositPercent ??= $outcome->grantedDepositPercent;
            if ($outcome->reason !== null) {
                $reasons[] = $outcome->reason;
            }
        }

        return new PaymentDecision(
            band: $bandAggregator->aggregate(array_map(static fn(PaymentDecision $o): Band => $o->band, $outcomes)),
            grantedTerm: $grantedTerm,
            grantedNetDays: $grantedNetDays,
            grantedDepositPercent: $grantedDepositPercent,
            reason: $reasons === [] ? null : implode('; ', $reasons),
        );
    }
}
