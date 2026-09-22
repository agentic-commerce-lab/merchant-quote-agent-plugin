<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration\Bench;

use MerchantQuoteAgentPlugin\Negotiation\NegotiationOutcome;
use MerchantQuoteAgentPlugin\Tests\Bench\BuyerMoveKind;

/**
 * What one bench negotiation finished as.
 *
 * `terminal` is null when the loop stopped WITHOUT the buyer ever accepting
 * or walking: the scenario's own `maxRounds` was reached with the buyer still
 * countering, or the pass itself escalated (see BenchNegotiation) and the
 * buyer was never asked to move again. `outcome` is always the last pipeline
 * pass's own result, so a caller can tell a round-cap timeout apart from a
 * round-cap reached while every pass kept offering.
 *
 * `order` is `OrderConversion::notAttempted()` whenever `terminal` is not
 * Accept, and carries either a real `orderId` or a non-null `orderFailure`
 * when it is — a quote the agent escalated, or one with no real offer on it,
 * may legitimately never become an order. That refusal is a bench finding,
 * not a sign the run itself failed.
 *
 * Two scalars, two small value objects, and `order` (itself a value object
 * for the same reason `order`'s own docblock gives): a plain promoted
 * constructor, not the single-array-parameter shape — nothing in src/ uses
 * that for a class this size (see e.g. Config\QuoteAgentSettings or
 * Servicing\Data\PassContext, both plain constructors of comparable width).
 */
final readonly class NegotiationResult
{
    public function __construct(
        public string $quoteId,
        public int $rounds,
        public ?BuyerMoveKind $terminal,
        public NegotiationOutcome $outcome,
        public OrderConversion $order,
    ) {}
}
