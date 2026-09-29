<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;

/**
 * When a floored offer (MarginFloorClamp) would give the buyer nothing: the
 * applier then holds the standing offer instead of re-pricing the lines.
 * Split out of MarginFloorClamp to keep per-class cyclomatic complexity
 * within the gate.
 */
final class FloorHold
{
    private function __construct() {}

    /**
     * Whether the floored prices would cost the buyer at least what the
     * standing quote already does. The standing offer then already sits at
     * the floor, and re-pricing it per unit can only lift the total by the
     * cents a rounded unit price cannot express: eval margin-floor-holds,
     * 10 x 727.23 stored as 7272.27, 8% standing = 6690.49, floor 669.05 a
     * unit = 6690.50. Measured on each line's own total, not unit x quantity,
     * for the same reason as PredictedWrite.
     *
     * @param ?ProposedOffer $floored MarginFloorClamp::clamp()'s answer; null (the floor does not bind) never holds
     * @param list<QuoteLineSnapshot> $liveLines the pre-write quote
     */
    public static function holds(?ProposedOffer $floored, array $liveLines): bool
    {
        if ($floored === null) {
            return false;
        }

        $named = [];
        foreach ($floored->price->linePricesNet ?? [] as $price) {
            $named[$price->lineItemId] = $price->unitPriceNet;
        }

        $factor = GoodsFactor::of($liveLines);
        $today = 0.0;
        $written = 0.0;
        foreach ($liveLines as $line) {
            if ($line->unitPriceNet <= 0.0) {
                continue;
            }

            $today += $line->totalNet * $factor;
            $price = $named[$line->lineItemId()] ?? null;
            // An unmoved line is not rewritten (OfferWrite::moved()) and keeps its own total.
            $written +=
                $price !== null && abs($price - MoneyMath::roundMoney($line->unitPriceNet)) > Epsilon::RATE
                    ? $price * $line->quantity
                    : $line->totalNet;
        }

        return $written >= ($today - Epsilon::RATE);
    }
}
