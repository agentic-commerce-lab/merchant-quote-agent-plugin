<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;
use MerchantQuoteAgentPlugin\Servicing\ServicingFingerprint;

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
     * True when some line asks for less than it is quoted at AND the agent
     * has not answered that ask yet.
     *
     * Below the quoted price, not merely present: `requested_price` is sticky —
     * SwagCommercial leaves it on the line after the agent has answered it, and
     * QuoteAutoReplyPricer only ever reads it as `min(requested, quoted)`. A
     * presence check would therefore make every later trigger on an answered
     * quote look like new work, which is the duplicate-servicing failure the
     * servicing fingerprint exists to prevent, arriving by another route.
     *
     * Not yet answered, because below the quoted price is not enough either: a
     * countered ask (80 asked, capped at 85) stays below it forever, and a later
     * "thanks" on that quote went back to the band, moved nothing and escalated
     * as no_further_concession with the buyer unable to accept. An ask the last
     * pass stamped (its `askToken()` is in the servicing marker) has been
     * answered; one the buyer edited carries a new token and is fresh — the
     * same comparison MerchantHandover::freshAskAt() makes. A buyer who insists
     * in a comment still has an ask: that is the interpretation, not this.
     *
     * No `Epsilon::MONEY` slack: QuoteLineNet rounds both sides to cents before
     * they reach here, so equal cents compare equal, and a cent of tolerance
     * would only discard a real — if small — ask, which is the silent no-op
     * this exists to remove.
     */
    public static function isOpen(QuoteSnapshot $snapshot): bool
    {
        $answered = explode(',', ServicingFingerprint::stampedAsks($snapshot->lifecycle->customFields));

        foreach ($snapshot->content->lines as $line) {
            if ($line->requestedUnitPrice === null || $line->requestedUnitPrice >= $line->unitPriceNet) {
                continue;
            }

            if (!\in_array(ServicingFingerprint::askToken($line), $answered, strict: true)) {
                return true;
            }
        }

        return false;
    }
}
