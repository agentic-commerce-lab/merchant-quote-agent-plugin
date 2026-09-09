<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteLineItemChange;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Bridge\Data\QuoteUpdate;
use MerchantQuoteAgentPlugin\Bridge\MirroredAsks;
use MerchantQuoteAgentPlugin\Bridge\QuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Policy\CommentTargetMerger;
use Psr\Log\LoggerInterface;

/**
 * Writes a per-line price the buyer asked for IN CHAT onto the line itself, so
 * the quote shows what was requested beside what is quoted.
 *
 * SwagCommercial 7.13 added per-line requests, and both UIs render
 * `quote_line_item.requested_price` directly — `-` when it is null. A buyer who
 * fills in the storefront's "Requested price" field therefore leaves a record
 * and a buyer who types the same number in the conversation left none: the
 * target reached CommentTargetMerger, priced the offer, and evaporated. Same
 * ask, same quote, two different documents.
 *
 * Deliberately BEFORE the ask gate and the decision, so an escalation and a
 * clarification carry the record too — an escalation is precisely when a human
 * opens the quote and needs to see the number that was asked for.
 *
 * Nothing here writes the ANSWER. A countered ask leaves `requested_price` at
 * the buyer's number, exactly as a sticky storefront ask does; the offer is the
 * unit price, and OfferApplier owns that.
 *
 * A static rather than a sixth constructor argument on NegotiationPipeline,
 * which is at its parameter cap — the same reason AskGate and StructuredAsk
 * are statics. The merger is stateless, so constructing one per call costs
 * nothing (QuoteDiscountApplier does the same).
 */
final class AskMirror
{
    private function __construct() {}

    /**
     * @throws \MerchantQuoteAgentPlugin\Bridge\QuoteNotFoundException deliberately
     *     not caught: a gateway that cannot write here cannot write the offer
     *     either, and swallowing it would only move the same failure later
     */
    public static function mirror(
        QuoteGatewayInterface $gateway,
        QuoteSnapshot $snapshot,
        ?InterpretedAsk $ask,
        LoggerInterface $logger,
    ): void {
        // Nullable because a structured-only ask reaches the pipeline with no
        // interpretation at all — the buyer typed nothing, so there is nothing
        // to mirror and the field they filled in already says it.
        //
        // The merger's own verdict, not the raw extraction: on a line where a
        // stale structured ask wins, the comment's target is never priced
        // against, and displaying a number the agent ignored is worse than
        // displaying none. See CommentTargetMerger::adopted().
        $adopted = (new CommentTargetMerger())->adopted(SnapshotAdapter::toPolicy($snapshot), $ask?->interpretation);

        if ($adopted === []) {
            return;
        }

        $quoteId = $snapshot->identity->quoteId;

        // Marker first, line second. A crash between the two leaves a marker
        // for a value never written, which hides nothing — the line still
        // holds null, or the buyer's own number, and neither matches. The
        // reverse order would leave the agent's own write looking like a fresh
        // buyer ask on the next pass, which is the one outcome to avoid.
        $gateway->updateQuote(
            $quoteId,
            new QuoteUpdate(customFields: MirroredAsks::stamp($snapshot->lifecycle->customFields, $adopted)),
        );

        $gateway->updateLineItems($quoteId, array_map(
            static fn(string $lineItemId): QuoteLineItemChange => new QuoteLineItemChange(
                lineItemId: $lineItemId,
                requestedUnitPriceNet: $adopted[$lineItemId],
            ),
            array_keys($adopted),
        ));

        $logger->info('The buyer\'s per-line ask was mirrored onto the quote.', [
            'quoteId' => $quoteId,
            'requestedUnitPricesNet' => $adopted,
        ]);
    }
}
