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
    ) {}
}
