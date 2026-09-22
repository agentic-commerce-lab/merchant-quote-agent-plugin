<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Bench;

use MerchantQuoteAgentPlugin\Bridge\Data\QuoteSnapshot;

/**
 * A negotiation-round driver standing in for a real buyer: given what the
 * agent just did to the quote and said about it, decides whether to accept,
 * counter, or walk away.
 *
 * `ScriptedBuyer` is the deterministic, free implementation used as a
 * regression gate; issue #21's `LlmBuyer` is the other implementation, used
 * for the benchmark itself.
 */
interface SyntheticBuyer
{
    public function respond(QuoteSnapshot $before, QuoteSnapshot $after, string $agentReply, int $round): BuyerMove;
}
