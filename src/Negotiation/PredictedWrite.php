<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Policy\Data\QuoteLifecycle as PolicyLifecycle;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot as PolicyLine;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteSnapshot as PolicySnapshot;
use MerchantQuoteAgentPlugin\Policy\Epsilon;
use MerchantQuoteAgentPlugin\Policy\GoodsFactor;

/**
 * The quote as it would read once an OfferWrite lands (design note
 * 2026-09-28), so OfferApplier can refuse a write BEFORE it is made.
 *
 * In the verifier's terms: every positive live line at the net price the buyer
 * would pay (the quote discount folded in, as OfferLanding prices it), negative
 * lines dropped, and the total moved by exactly what the goods moved. The
 * expiry is left null, so the prediction does not check it: the write sets it
 * from validityDays, and the post-write verification checks what the database
 * kept.
 */
final readonly class PredictedWrite
{
    /**
     * @param list<string> $refusals every line the write would price above what the buyer pays today, and
     *                               a quote whose discount the prediction cannot see
     */
    private function __construct(
        public PolicySnapshot $snapshot,
        public array $refusals,
    ) {}

    /** @param PolicySnapshot $live the pre-write quote, never the baseline */
    public static function of(OfferWrite $write, PolicySnapshot $live): self
    {
        $named = [];
        foreach ($write->lines as $price) {
            $named[$price->lineItemId] = $price->unitPriceNet;
        }

        $goodsFactor = GoodsFactor::of($live->lines);
        $factor = $write->discount === null ? $goodsFactor : 1 - ($write->discount->value / 100);
        $lines = [];
        $refusals = [];
        $moved = 0.0;
        $goods = 0.0;
        foreach ($live->lines as $line) {
            if ($line->unitPriceNet <= 0.0) {
                continue;
            }

            $goods += $line->unitPriceNet * $line->quantity;
            $today = $line->unitPriceNet * $goodsFactor;
            $price = ($named[$line->lineItemId()] ?? $line->unitPriceNet) * $factor;
            $moved += ($price - $today) * $line->quantity;
            $lines[] = new PolicyLine($line->identity, $line->quantity, $price, $price * $line->quantity);

            // Never-raise, per line: OfferApplier changes no quantity or
            // product, so no structural change can explain a higher price.
            if ($price > ($today + Epsilon::MONEY)) {
                $refusals[] = sprintf(
                    'line "%s" would rise from %.2f to %.2f net, above what the buyer pays today',
                    $line->label() ?? $line->lineItemId(),
                    $today,
                    $price,
                );
            }
        }

        // GoodsFactor reads the quote discount off its negative line. A total
        // below the goods with no such line means something takes money off
        // that the prediction cannot see, and a hold would write 0% over it.
        if ($goodsFactor >= 1.0 && $live->totalNet < ($goods - Epsilon::MONEY)) {
            $refusals[] = sprintf(
                'the quote total %.2f is below its lines %.2f with no discount line to account for it',
                $live->totalNet,
                $goods,
            );
        }

        return new self(
            new PolicySnapshot(
                currencyIso: $live->currencyIso,
                totalNet: $live->totalNet + $moved,
                lines: $lines,
                lifecycle: new PolicyLifecycle($live->lifecycle->stateTechnicalName),
            ),
            $refusals,
        );
    }
}
