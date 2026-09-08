<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * The shop's single source of truth for negotiation.
 *
 * Delivery and payment used to live here too. They were removed once it was
 * clear AskGate escalates every non-price ask to a human before any decider
 * runs, so the two sub-policies tuned logic that could not execute while the
 * signed mandate still advertised them. The extract side still reads those asks
 * -- that is what makes the escalation possible -- it just has no policy to
 * measure them against.
 */
final readonly class NegotiationPolicy
{
    public function __construct(
        #[Assert\Valid]
        public QuoteLimits $price,
        #[Assert\Valid]
        public ?BundlePolicy $bundle = null,
    ) {}

    public function withPrice(QuoteLimits $price): self
    {
        return new self(price: $price, bundle: $this->bundle);
    }

    /** @throws \TypeError|\ValueError|\CuyZ\Valinor\Mapper\MappingError */
    public static function fromArray(array $data): self
    {
        $bundle = OptionalShape::array($data, 'bundle');

        return new self(
            price: QuoteLimits::fromArray(NestedShape::array($data, 'price')),
            bundle: $bundle === null ? null : ArrayMapper::mapObject(BundlePolicy::class, $bundle),
        );
    }
}
