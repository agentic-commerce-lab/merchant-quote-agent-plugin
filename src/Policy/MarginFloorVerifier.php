<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot;

/**
 * The authoritative half of the minimum-margin floor (spec 2026-09-24): after
 * the write, every floored line must still cost the buyer at least its floor,
 * counting any quote discount that stacked on top. MarginFloorClamp keeps the
 * write there; this catches whatever the clamp could not see (a rounding
 * surprise, an absolute discount whose share shifted, a bug in the clamp).
 *
 * The message carries the floor, never the purchase price. It reaches the log
 * and the audit record, which only the merchant reads.
 */
final class MarginFloorVerifier
{
    /**
     * @param array<string, float> $floors lineItemId => effective floor net
     *
     * @return list<string>
     */
    public function verify(QuoteSnapshot $final, array $floors): array
    {
        $goodsFactor = GoodsFactor::of($final->lines);
        $violations = [];

        foreach ($final->lines as $line) {
            $floor = $floors[$line->lineItemId()] ?? null;
            $effective = $line->unitPriceNet * $goodsFactor;

            if ($floor !== null && $line->unitPriceNet > 0.0 && $effective < ($floor - Epsilon::MONEY)) {
                $violations[] = sprintf(
                    'line "%s" priced %s net below its minimum-margin floor %s',
                    $line->label() ?? $line->lineItemId(),
                    number_format($effective, decimals: 2, thousands_separator: ''),
                    number_format($floor, decimals: 2, thousands_separator: ''),
                );
            }
        }

        return $violations;
    }
}
