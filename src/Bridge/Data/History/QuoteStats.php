<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge\Data\History;

/**
 * The company's quote record, aggregated for the brief.
 *
 * `converted` and `lost` come from the QUOTE (its `orderId` and its state), not
 * from our decision table: both are authoritative and both exist for quotes
 * that predate this plugin. `offersMade` / `offersAccepted` come from
 * merchant_quote_agent_decision, which is the only place that knows we made an
 * offer at all.
 */
final readonly class QuoteStats
{
    /**
     * @mago-expect lint:excessive-parameter-list
     * A data carrier's promoted properties ARE its interface, and every
     * construction site uses named arguments, so the call-site complexity
     * this rule exists to catch does not arise here.
     */
    public function __construct(
        public int $seen = 0,
        public int $converted = 0,
        public int $lost = 0,
        public int $offersMade = 0,
        public int $offersAccepted = 0,
        public ?float $lastGrantedDiscountPercent = null,
    ) {}
}
