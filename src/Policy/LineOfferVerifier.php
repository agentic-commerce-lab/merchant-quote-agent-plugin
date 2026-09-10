<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLimits;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * Ported from `verifyLines` in the retired TS agent (policy/offer-verification.ts).
 */
final class LineOfferVerifier
{
    /** @return list<string> */
    public function verify(QuoteSnapshot $reference, QuoteSnapshot $final, QuoteLimits $limits): array
    {
        $referenceById = $this->byLineItemId($reference->lines);
        $maxLineFactor = 1 - ($limits->maxDiscountPercent / 100);
        $referenceToNet = NetFactor::of($reference);
        $finalToNet = NetFactor::of($final);

        $violations = [];
        foreach ($final->lines as $line) {
            // Lines absent from the reference are Shopware-generated; the
            // totals check covers their effect.
            $referenceLine = $referenceById[$line->lineItemId()] ?? null;
            if ($referenceLine === null) {
                continue;
            }
            array_push($violations, ...LineNetViolation::check(
                $line,
                $referenceLine,
                $finalToNet,
                $referenceToNet,
                $maxLineFactor,
            ));
        }

        return $violations;
    }

    /**
     * @param list<QuoteLineSnapshot> $lines
     * @return array<string, QuoteLineSnapshot>
     */
    private function byLineItemId(array $lines): array
    {
        $byId = [];
        foreach ($lines as $line) {
            $byId[$line->lineItemId()] = $line;
        }

        return $byId;
    }
}
