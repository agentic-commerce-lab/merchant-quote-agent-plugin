<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;

/**
 * The concession terms of a negotiate response — everything ProposedOffer
 * needs except the order total, which only the caller (Servicing) knows, and
 * the reference lines, which only the proposer holds.
 *
 * Those two absences are why this is a wire twin of OfferedPrice rather than
 * OfferedPrice itself: the schema handed to the model must not contain a field
 * the model has no business filling in.
 *
 * Split out of NegotiateResponse so that class's own constructor stays under
 * the parameter-count gate.
 *
 * Price only. `delivery` and `payment` were fields here until those dimensions
 * were retired; leaving them would invite the model to propose a term that
 * AskGate escalates, no check bounds any more, and QuoteUpdate cannot write —
 * a concession promised to the buyer and then silently dropped.
 */
final readonly class OfferTerms
{
    /** @param list<QuoteLinePrice>|null $linePricesNet */
    public function __construct(
        public ?float $discountPercent = null,
        public ?array $linePricesNet = null,
    ) {}

    /**
     * Both a quote-wide discount and per-line prices. OfferApplier writes the
     * lines and silently drops the discount, so the offer the buyer is told
     * about would not be the offer the database holds. Unusable, not a choice.
     */
    public function contradictory(): bool
    {
        return $this->discountPercent !== null && $this->lines() !== null;
    }

    public function toOffer(float $orderTotalNet): ProposedOffer
    {
        return new ProposedOffer(
            orderTotalNet: $orderTotalNet,
            price: new OfferedPrice(discountPercent: $this->discountPercent, linePricesNet: $this->lines()),
        );
    }

    /**
     * `[]` and null both mean "no per-line concession". A model under a schema
     * that permits an array will sometimes send the empty one, and an empty
     * list read as a per-line offer would make contradictory() fire on a plain
     * quote-wide discount and LinePriceOfferCheck bound nothing.
     *
     * @return list<QuoteLinePrice>|null
     */
    private function lines(): ?array
    {
        return $this->linePricesNet === [] ? null : $this->linePricesNet;
    }
}
