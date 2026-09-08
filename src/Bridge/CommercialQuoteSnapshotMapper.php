<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;

/**
 * Turns a SwagCommercial quote entity into the snapshot published to buyer
 * agents.
 *
 * Separate from the gateway because it is a different job: the gateway calls
 * the commercial Store API routes, this reads whatever comes back. The
 * parameter is typed `object` for the same reason the gateway's routes are —
 * the concrete QuoteEntity class lives in a runtime-detected soft dependency
 * this plugin never requires.
 */
final readonly class CommercialQuoteSnapshotMapper
{
    public function __construct(
        private CommercialCapabilities $capabilities,
    ) {}

    /**
     * @mago-expect analysis:mixed-method-access
     * @mago-expect analysis:mixed-argument
     * Every field here is read off an untyped SwagCommercial entity — the
     * same soft-dependency seam SwagCommercialProductAdder suppresses at one
     * call, scaled up because one snapshot touches many fields. There is no
     * SwagCommercial type to narrow to; BuyerQuoteFlowTest proves the mapping
     * is correct against the live shop.
     */
    public function toSnapshot(object $quote): QuoteSnapshot
    {
        return new QuoteSnapshot(
            id: (string) $quote->getId(),
            quoteNumber: (string) $quote->getQuoteNumber(),
            state: $quote->getStateMachineState()?->getTechnicalName(),
            expirationDate: $quote->getExpirationDate()?->format(\DateTimeInterface::ATOM),
            currency: $quote->getCurrency()?->getIsoCode(),
            totalGross: $quote->getAmountTotal(),
            totalNet: $quote->getAmountNet(),
            taxStatus: $quote->getTaxStatus(),
            lineItems: $this->mapLineItems($quote),
            comments: $this->mapComments($quote),
        );
    }

    /**
     * @mago-expect analysis:invalid-iterator
     * @mago-expect analysis:mixed-method-access
     * @mago-expect analysis:less-specific-nested-return-statement
     * `$quote->getLineItems()` and every field on each item are untyped
     * SwagCommercial values — no type to narrow to; BuyerQuoteFlowTest proves
     * the shape against the live shop.
     *
     * @return list<array{id: string, product_id: string|null, label: string, quantity: int, unit_price: float, total_price: float, requested_unit_price: float|null}>
     */
    private function mapLineItems(object $quote): array
    {
        $lineItems = [];

        foreach ($quote->getLineItems() ?? [] as $lineItem) {
            $lineItems[] = [
                'id' => (string) $lineItem->getId(),
                'product_id' => $lineItem->getProductId(),
                'label' => (string) $lineItem->getLabel(),
                'quantity' => (int) $lineItem->getQuantity(),
                // Per unit, in the quote currency; gross or net per totals.tax_status.
                'unit_price' => (float) $lineItem->getUnitPrice(),
                'total_price' => (float) $lineItem->getTotalPrice(),
                // A method, not a column: on a SwagCommercial without line-item
                // asks this getter does not exist, and calling it is a fatal
                // Error on every buyer-side read rather than a null.
                'requested_unit_price' => $this->capabilities->lineItemAsks ? $lineItem->getRequestedPrice() : null,
            ];
        }

        return $lineItems;
    }

    /**
     * @mago-expect analysis:invalid-iterator
     * @mago-expect analysis:mixed-method-access
     * @mago-expect analysis:less-specific-nested-return-statement
     * `$quote->getComments()` and every field on each comment are untyped
     * SwagCommercial values — no type to narrow to; BuyerQuoteFlowTest proves
     * the shape against the live shop.
     *
     * @return list<array{comment: string, author: string, created_at: string|null}>
     */
    private function mapComments(object $quote): array
    {
        $comments = [];

        foreach ($quote->getComments() ?? [] as $comment) {
            $comments[] = [
                'comment' => (string) $comment->getComment(),
                'author' => null !== $comment->getCustomerId() ? 'buyer' : 'merchant',
                'created_at' => $comment->getCreatedAt()?->format(\DateTimeInterface::ATOM),
            ];
        }

        return $comments;
    }
}
