<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The shop's single source of truth for negotiation: the price bands, and
 * nothing else.
 *
 * Delivery, payment and the published volume tiers used to live here too. They
 * were removed once it was clear AskGate escalates every non-price ask to a
 * human before any decider runs, so the sub-policies tuned logic that could not
 * execute while the signed mandate still advertised them. The extract side
 * still reads those asks -- that is what makes the escalation possible -- it
 * just has no policy to measure them against.
 */
final readonly class NegotiationPolicy
{
    public function __construct(
        #[Assert\Valid]
        public QuoteLimits $price,
    ) {}

    public function withPrice(QuoteLimits $price): self
    {
        return new self(price: $price);
    }

    /** @throws \TypeError|\ValueError */
    public static function fromArray(array $data): self
    {
        return new self(price: QuoteLimits::fromArray(NestedShape::array($data, 'price')));
    }
}
