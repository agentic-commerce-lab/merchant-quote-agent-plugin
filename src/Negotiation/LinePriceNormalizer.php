<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLineSnapshot as PolicyQuoteLineSnapshot;

/**
 * Resolves proposed line item IDs against the actual quote lines.
 *
 * An LLM may propose prices using a product slug (e.g. "fusionglow_sport") or label
 * instead of the internal line item UUID. This normalizer maps them back to the real
 * UUID so downstream authorization and database checks recognize them.
 */
final readonly class LinePriceNormalizer
{
    /**
     * @param list<PolicyQuoteLineSnapshot> $lines
     */
    public static function normalize(ProposedOffer $offer, array $lines): ProposedOffer
    {
        if ($offer->price->linePricesNet === null) {
            return $offer;
        }

        $validIds = [];
        $bySlug = [];
        foreach ($lines as $line) {
            $id = $line->lineItemId();
            $validIds[$id] = true;
            $label = $line->label();
            if ($label !== null) {
                $slug = strtolower((string) preg_replace(pattern: '/[^a-z0-9]/', replacement: '', subject: $label));
                if ($slug !== '') {
                    $bySlug[$slug] = $id;
                }
            }
        }

        $singleLineId = count($lines) === 1 ? $lines[0]->lineItemId() : null;

        $resolved = [];
        foreach ($offer->price->linePricesNet as $linePrice) {
            $resolvedId = self::resolveLineItemId($linePrice->lineItemId, $validIds, $bySlug, $singleLineId);
            $resolved[] = new QuoteLinePrice($resolvedId, $linePrice->unitPriceNet);
        }

        return new ProposedOffer(
            orderTotalNet: $offer->orderTotalNet,
            price: new OfferedPrice(
                discountPercent: $offer->price->discountPercent,
                linePricesNet: $resolved,
                referenceLines: $offer->price->referenceLines,
            ),
            delivery: $offer->delivery,
            payment: $offer->payment,
        );
    }

    /**
     * @param array<string, true> $validIds
     * @param array<string, string> $bySlug
     */
    private static function resolveLineItemId(
        string $currentId,
        array $validIds,
        array $bySlug,
        ?string $singleLineId,
    ): string {
        if (\array_key_exists($currentId, $validIds)) {
            return $currentId;
        }

        $currentSlug = strtolower((string) preg_replace(pattern: '/[^a-z0-9]/', replacement: '', subject: $currentId));
        if ($currentSlug !== '' && \array_key_exists($currentSlug, $bySlug)) {
            return $bySlug[$currentSlug];
        }

        return $singleLineId ?? $currentId;
    }
}
