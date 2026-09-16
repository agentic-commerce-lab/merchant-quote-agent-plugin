<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot;

/**
 * Makes the model's answer come back at the level the buyer asked at (#47).
 *
 * The negotiate prompt already demands this in capitals, and a prompt is not
 * an enforcement mechanism: a buyer who itemised their ask could still receive
 * a quote-wide percentage, because `OfferTerms` takes whatever the model
 * returned and `OfferApplier` writes a quote-level discount whenever no line
 * prices are present. The rules-only path never had the problem —
 * `OfferProposer::deterministicOffer()` picks the level from `perLineAsks` —
 * so this closes the same gap on the model path.
 *
 * Only one direction is corrected. A quote-wide answer to a per-line ask is
 * re-expressed as line prices; a per-line answer to a quote-wide ask is left
 * alone, because per-line prices satisfy a quote-wide ask precisely, already
 * write correctly, and are bounded line by line by LinePriceOfferCheck.
 * Converting upward would mean deriving an implied percentage from summed line
 * totals, which rounds badly for a case nobody has reported.
 *
 * The conversion changes how the concession is written, never how large it is:
 * the model's percentage is applied to EVERY reference line, which reproduces
 * the line-net total the quote-wide discount would have produced. Pricing only
 * the asked lines would quietly shrink the offer the model decided to make.
 *
 * Safe against telling the buyer one thing and writing another — the hazard
 * `OfferTerms::contradictory()` exists for — because the buyer's comment is
 * not the model's prose: ReplyComposer builds it from the POST-WRITE snapshot
 * through ReplyTemplate::compose(), and RewordingGuard::unsafeBecause() rejects
 * a rewording that drops a number.
 */
final class OfferLevelMirror
{
    /**
     * @param list<QuoteLineSnapshot> $referenceLines the pre-negotiation lines
     *     the round was decided on — the same ones the caller attaches with
     *     ProposedOffer::withReferenceLines(), and what the converted prices
     *     are computed from
     */
    public static function mirror(ProposedOffer $offer, array $referenceLines): ProposedOffer
    {
        $percent = $offer->price->discountPercent;

        if ($percent === null || $offer->price->linePricesNet !== null || !self::askedPerLine($referenceLines)) {
            return $offer;
        }

        return new ProposedOffer(
            orderTotalNet: $offer->orderTotalNet,
            price: new OfferedPrice(
                discountPercent: null,
                linePricesNet: self::atPercent($referenceLines, $percent),
                referenceLines: $referenceLines,
            ),
        );
    }

    /**
     * The same predicate QuoteAutoReplyPricer applies privately: a buyer on
     * trunk itemises an ask by putting a requested price on the line.
     *
     * @param list<QuoteLineSnapshot> $lines
     */
    private static function askedPerLine(array $lines): bool
    {
        foreach ($lines as $line) {
            if ($line->requestedUnitPrice !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Deliberately prices every line the same way QuoteAutoReplyPricer's
     * uniform branch does, negative Shopware-generated lines included. Matching
     * the path that already runs in production is the conservative choice;
     * diverging here would be inventing pricing policy in a conversion.
     *
     * @param list<QuoteLineSnapshot> $lines
     *
     * @return list<QuoteLinePrice>
     */
    private static function atPercent(array $lines, float $percent): array
    {
        $factor = 1 - ($percent / 100);
        $prices = [];

        foreach ($lines as $line) {
            $prices[] = new QuoteLinePrice(
                lineItemId: $line->lineItemId(),
                unitPriceNet: MoneyMath::roundMoney($line->unitPriceNet * $factor),
            );
        }

        return $prices;
    }
}
