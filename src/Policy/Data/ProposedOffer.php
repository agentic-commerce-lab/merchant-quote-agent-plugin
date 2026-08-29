<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The concession the agent proposes to give. Grouped by dimension, mirroring
 * NegotiationProposal/NegotiationPolicy — any field left null is "not offered".
 */
final readonly class ProposedOffer
{
    public function __construct(
        #[Assert\PositiveOrZero]
        public float $orderTotalNet,
        public OfferedPrice $price = new OfferedPrice(),
        public OfferedDelivery $delivery = new OfferedDelivery(),
        public OfferedPayment $payment = new OfferedPayment(),
    ) {}

    /**
     * The pre-negotiation lines every proposed line price is bounded against.
     * Only the proposer can supply them — it holds the snapshot the round was
     * decided on — and without them LinePriceOfferCheck has no reference at
     * all and rejects every per-line offer as "not on this quote".
     *
     * @param list<QuoteLineSnapshot> $lines
     */
    public function withReferenceLines(array $lines): self
    {
        return new self(
            orderTotalNet: $this->orderTotalNet,
            price: new OfferedPrice(
                discountPercent: $this->price->discountPercent,
                linePricesNet: $this->price->linePricesNet,
                referenceLines: $lines,
            ),
            delivery: $this->delivery,
            payment: $this->payment,
        );
    }
}
