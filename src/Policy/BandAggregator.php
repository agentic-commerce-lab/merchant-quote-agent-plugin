<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;

/**
 * Any dimension escalates ⇒ escalate; else any counters ⇒ counter; else
 * grant. Ported from `aggregate` in the retired TS agent (policy/negotiate-dimensions.ts).
 */
final class BandAggregator
{
    /** @param list<Band> $bands */
    public function aggregate(array $bands): Band
    {
        if (in_array(Band::Escalate, $bands, strict: true)) {
            return Band::Escalate;
        }

        return in_array(Band::Counter, $bands, strict: true) ? Band::Counter : Band::Grant;
    }
}
