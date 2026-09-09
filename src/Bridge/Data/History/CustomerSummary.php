<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data\History;

/**
 * Everything CustomerBrief renders, plus whether it could be read at all.
 *
 * `available: false` is not an error path — it is the pre-#100 behaviour with a
 * reason attached, so the audit record can say why a pass negotiated blind.
 */
final readonly class CustomerSummary
{
    public function __construct(
        public bool $available = true,
        public ?string $unavailableReason = null,
        public QuoteStats $quotes = new QuoteStats(),
        public OrderStats $orders = new OrderStats(),
    ) {}

    public static function unavailable(string $reason): self
    {
        return new self(available: false, unavailableReason: $reason);
    }
}
