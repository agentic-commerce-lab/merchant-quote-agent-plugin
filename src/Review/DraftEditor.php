<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Review;

use MerchantQuoteAgentPlugin\Bridge\Data\Discount;
use MerchantQuoteAgentPlugin\Bridge\Data\DiscountType;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;

/**
 * Writes the merchant's edits into the draft version and recalculates it
 * there, so Preview and Send read the totals Shopware itself computes. The
 * same writers the agent's offer used — only the author differs.
 */
final class DraftEditor
{
    private function __construct() {}

    /**
     * @return QuoteSnapshot the draft as it stands after the edits
     *
     * @throws InvalidReviewRequest
     */
    public static function apply(PendingDraft $pending, DraftEdits $edits): QuoteSnapshot
    {
        $gateway = $pending->draft;
        $quoteId = $pending->record->quoteId;

        if ($gateway === null) {
            if (!$edits->isEmpty()) {
                throw InvalidReviewRequest::because(
                    InvalidReviewReason::NoPrices,
                    'This draft changes no prices, so there are none to edit.',
                );
            }

            return $pending->live;
        }

        $before = $gateway->fetchSnapshot($quoteId);

        if ($edits->isEmpty()) {
            return $before;
        }

        self::assertLinesBelong($before, $edits);

        if ($edits->linePrices !== []) {
            $changes = [];

            foreach ($edits->linePrices as $lineItemId => $unitPriceNet) {
                $changes[] = new QuoteLineItemChange($lineItemId, unitPriceNet: $unitPriceNet);
            }

            $gateway->updateLineItems($quoteId, $changes);
        }

        if ($edits->discountPercent !== null || $edits->expiresAt !== null) {
            $gateway->updateQuote(
                $quoteId,
                new QuoteUpdate(
                    discount: $edits->discountPercent === null
                        ? null
                        : new Discount(DiscountType::Percentage, $edits->discountPercent),
                    expiresAt: $edits->expiresAt,
                ),
            );
        }

        $gateway->recalculate($quoteId);

        return $gateway->fetchSnapshot($quoteId);
    }

    /** @throws InvalidReviewRequest */
    private static function assertLinesBelong(QuoteSnapshot $draft, DraftEdits $edits): void
    {
        $known = array_map(
            static fn(QuoteLineSnapshot $line): string => $line->identity->lineItemId,
            $draft->content->lines,
        );

        if (array_diff(array_keys($edits->linePrices), $known) !== []) {
            throw InvalidReviewRequest::because(
                InvalidReviewReason::UnknownLine,
                'One of the edited lines is not on this quote.',
            );
        }
    }
}
