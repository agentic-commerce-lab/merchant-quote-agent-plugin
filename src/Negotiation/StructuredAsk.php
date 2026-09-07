<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;

/**
 * The ask that arrives without a comment: a per-line target the buyer entered
 * in the storefront, which SwagCommercial writes to
 * `quote_line_item.requested_price`.
 *
 * Its own class rather than a method on NegotiationPipeline because the
 * pipeline is already at the gate's class-complexity ceiling, and because this
 * is a question about a snapshot, not about a pass.
 */
final class StructuredAsk
{
    private function __construct() {}

    /**
     * True when some line asks for less than it is quoted at.
     *
     * Below the quoted price, not merely present: `requested_price` is sticky —
     * SwagCommercial leaves it on the line after the agent has answered it, and
     * QuoteAutoReplyPricer only ever reads it as `min(requested, quoted)`. A
     * presence check would therefore make every later trigger on an answered
     * quote look like new work, which is the duplicate-servicing failure the
     * servicing fingerprint exists to prevent, arriving by another route.
     *
     * No `Epsilon::MONEY` slack: QuoteLineNet rounds both sides to cents before
     * they reach here, so equal cents compare equal, and a cent of tolerance
     * would only discard a real — if small — ask, which is the silent no-op
     * this exists to remove.
     */
    public static function isUnmet(QuoteSnapshot $snapshot): bool
    {
        foreach ($snapshot->content->lines as $line) {
            if ($line->requestedUnitPrice !== null && $line->requestedUnitPrice < $line->unitPriceNet) {
                return true;
            }
        }

        return false;
    }
}
