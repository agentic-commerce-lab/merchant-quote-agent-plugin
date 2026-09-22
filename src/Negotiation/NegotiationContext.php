<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation;

/** Quote-owned identity and conversation carried unchanged through a negotiation round. */
final readonly class NegotiationContext
{
    public function __construct(
        public string $customerId,
        public string $quoteId,
        public BuyerConversation $conversation,
        public ?QuoteBaselineLines $baseline = null,
        /**
         * The whole-quote total the buyer named, already out of their own tax
         * space — see BuyerPriceSpace. Null when they named no total.
         *
         * Carried here rather than derived downstream because the negotiate
         * prompt is the only stage that both prices in net and reads the
         * buyer's own sentence, where the figure is still gross.
         */
        public ?float $buyerTargetNet = null,
    ) {}
}
