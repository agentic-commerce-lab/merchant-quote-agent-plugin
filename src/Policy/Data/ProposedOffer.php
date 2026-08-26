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
}
