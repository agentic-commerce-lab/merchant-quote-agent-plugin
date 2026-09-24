<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;

/**
 * The net unit price each positive line would actually cost the buyer once an
 * offer is written as proposed: a quote-wide percentage REPLACES any existing
 * quote discount and acts on the LIVE prices, while a per-line write keeps the
 * discount on top. Split out of MarginFloorClamp to keep per-class cyclomatic
 * complexity within the gate.
 *
 * This is the BINDING question only -- whether the floor fires. It is what the
 * write does when the clamp returns null, so the null path stays safe. What
 * the clamp writes once it fires is OfferConversion's answer, which differs
 * for a quote-wide offer on purpose (idempotence across rounds and retries).
 */
final class OfferLanding
{
    private function __construct() {}

    /**
     * @param list<QuoteLineSnapshot> $liveLines the pre-write quote, never the baseline
     *
     * @return array<string, float> lineItemId => net unit price the buyer would pay
     */
    public static function of(ProposedOffer $offer, array $liveLines): array
    {
        $named = [];
        foreach ($offer->price->linePricesNet ?? [] as $price) {
            $named[$price->lineItemId] = $price->unitPriceNet;
        }

        // An empty per-line list is written as a quote-wide discount
        // (OfferApplier::write()), so it is priced as one here too.
        $factor = $named === [] ? self::quoteWideFactor($offer) : GoodsFactor::of($liveLines);

        $landing = [];
        foreach ($liveLines as $line) {
            if ($line->unitPriceNet <= 0.0) {
                continue;
            }

            $landing[$line->lineItemId()] = ($named[$line->lineItemId()] ?? $line->unitPriceNet) * $factor;
        }

        return $landing;
    }

    /** What a quote-wide "p% off" leaves of a price: 1 − p/100. */
    public static function quoteWideFactor(ProposedOffer $offer): float
    {
        return 1 - (($offer->price->discountPercent ?? 0.0) / 100);
    }
}
