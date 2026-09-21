<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

/**
 * ponytail: trimmed to the fields negotiation-core actually reads
 * (currencyIso, totalNet, lines, buyerTargetNet, lifecycle). The TS contract
 * also carries quote/comment identity (quoteId, quoteNumber, buyerComments,
 * agentComments, supportsQuoteDiscount, shippingCostNet, customFields) for
 * the Servicing/Protocol modules — none of it is read by any function ported
 * in this issue, so it is not speculatively modeled here; issue #4 (Servicing
 * loop) extends this DTO when it has a real reader for those fields.
 */
final readonly class QuoteSnapshot
{
    /** @param list<QuoteLineSnapshot> $lines */
    public function __construct(
        public string $currencyIso,
        public float $totalNet,
        public array $lines,
        public QuoteLifecycle $lifecycle,
        public ?float $buyerTargetNet = null,
    ) {}

    /** @throws \TypeError|\ValueError */
    public static function fromArray(array $data): self
    {
        return new self(
            currencyIso: RequiredShape::string($data, 'currencyIso'),
            totalNet: RequiredShape::float($data, 'totalNet'),
            lines: ListShape::of($data, 'lines', QuoteLineSnapshot::fromArray(...)),
            lifecycle: new QuoteLifecycle(
                stateTechnicalName: RequiredShape::string($data, 'stateTechnicalName'),
                expirationDate: OptionalShape::string($data, 'expirationDate'),
            ),
            buyerTargetNet: OptionalShape::float($data, 'buyerTargetNet'),
        );
    }

    /** @param list<QuoteLineSnapshot> $lines */
    public function withLines(array $lines): self
    {
        return new self(
            currencyIso: $this->currencyIso,
            totalNet: $this->totalNet,
            lines: $lines,
            lifecycle: $this->lifecycle,
            buyerTargetNet: $this->buyerTargetNet,
        );
    }

    /**
     * The same snapshot with a budget the buyer named for the WHOLE quote
     * applied to the quote-level target the band decider reads.
     *
     * Here rather than in QuoteDiscountApplier, which has no complexity left
     * for it: a clamp against `totalNet` is this DTO's own arithmetic anyway.
     * `min`, because two asks in one comment are both the buyer's and the
     * deeper one is the one they meant — and because a budget at or above the
     * quoted total then asks for nothing, which is the right answer for it.
     */
    public function cappedAtBudget(?float $budgetNet): self
    {
        // ponytail: the budget becomes a percentage of `totalNet`, which
        // includes shipping, while QuoteAutoReplyPricer only scales the lines
        // — so a quote with shipping on it lands a few euro ABOVE the budget.
        // Make the percentage goods-only if a buyer ever disputes the
        // difference.

        // Returning `$this` untouched, not a target of `totalNet`, when no
        // budget was named: a quote with no quote-level ask at all must keep a
        // NULL target, or MoneyMath::requestedDiscount() starts answering 0.0
        // where it answered null and every escalation record on a quote nobody
        // made a price ask on reads as a 0% ask.
        return $budgetNet === null
            ? $this
            : $this->withBuyerTargetNet(min($budgetNet, $this->buyerTargetNet ?? $this->totalNet));
    }

    public function withBuyerTargetNet(?float $buyerTargetNet): self
    {
        return new self(
            currencyIso: $this->currencyIso,
            totalNet: $this->totalNet,
            lines: $this->lines,
            lifecycle: $this->lifecycle,
            buyerTargetNet: $buyerTargetNet,
        );
    }
}
