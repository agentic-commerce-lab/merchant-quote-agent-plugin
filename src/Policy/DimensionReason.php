<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\Band;

/**
 * Port of `dimensionReason` in src/policy/negotiate-decision.ts.
 */
final class DimensionReason
{
    /** @return list<string> */
    public function reason(string $label, ?Band $band, ?string $reason): array
    {
        if ($band !== Band::Escalate) {
            return [];
        }

        return [sprintf('%s: %s', $label, $reason ?? 'not permitted by policy')];
    }
}
