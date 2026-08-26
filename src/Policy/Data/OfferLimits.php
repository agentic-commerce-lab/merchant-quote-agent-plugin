<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

/**
 * The bands the agent is allowed to operate within — what it's told before
 * it proposes an offer. Same numbers OfferAuthorizer enforces afterwards.
 */
final readonly class OfferLimits
{
    public function __construct(
        public float $maxDiscountPercent,
        public PaymentOfferLimits $payment = new PaymentOfferLimits(),
        public DeliveryOfferLimits $delivery = new DeliveryOfferLimits(),
    ) {}
}
