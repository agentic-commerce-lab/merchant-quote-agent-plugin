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
