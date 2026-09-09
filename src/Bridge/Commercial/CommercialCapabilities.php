<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Commercial;

/**
 * What the SwagCommercial in front of us can do, as opposed to whether it is
 * there at all — that second question is {@see CommercialAvailability}'s, and
 * the two stay separate because they gate different things: availability
 * decides whether the bridge is registered, this decides how it behaves once
 * it is.
 *
 * Released SwagCommercial (6.7.1.2 through 6.7.12.x) has none of these four;
 * trunk has all four. They are separate booleans rather than one `legacy` flag
 * because SwagCommercial backports schema into patch releases — `quote.cart_payload`
 * landed in 6.7.9, mid-line — so a shop holding some of them and not others is
 * a state that will occur, and the first candidate for a backport is
 * `requested_price`, the very field blocking these pilots.
 */
final readonly class CommercialCapabilities
{
    public function __construct(
        /** `quote_line_item.requestedPrice`: the buyer's own per-unit ask. */
        public bool $lineItemAsks,
        /** `quote_line_item.deletedAt`: removal is a soft delete, not a delete. */
        public bool $softDeleteLines,
        /** `quote_comment.quoteLineItemId`: a comment can be scoped to one line. */
        public bool $lineScopedComments,
        /** A quote request creates a draft that a second route then sends. */
        public bool $draftBeforeSend,
    ) {}

    /** Trunk, and any release that has caught up with it. */
    public static function modern(): self
    {
        return new self(lineItemAsks: true, softDeleteLines: true, lineScopedComments: true, draftBeforeSend: true);
    }

    /** Released SwagCommercial through 6.7.12.x. */
    public static function legacy(): self
    {
        return new self(lineItemAsks: false, softDeleteLines: false, lineScopedComments: false, draftBeforeSend: false);
    }
}
