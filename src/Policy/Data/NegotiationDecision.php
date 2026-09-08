<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class NegotiationDecision
{
    /** @param list<string> $escalationReasons */
    public function __construct(
        public Band $overall,
        public QuoteDecision $price,
        public array $escalationReasons = [],
    ) {}
}
