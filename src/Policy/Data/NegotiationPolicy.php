<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

/**
 * The shop's single source of truth for negotiation. Every optional
 * sub-policy is escalate-by-default: undefined means an ask in that
 * dimension always escalates to a human.
 */
final readonly class NegotiationPolicy
{
    public function __construct(
        public QuoteLimits $price,
        public ?DeliveryPolicy $delivery = null,
        public ?PaymentPolicy $payment = null,
        public ?BundlePolicy $bundle = null,
    ) {}

    /** @throws \TypeError|\ValueError */
    public static function fromArray(array $data): self
    {
        return new self(
            price: QuoteLimits::fromArray(NestedShape::array($data, 'price')),
            delivery: NestedShape::object($data, 'delivery', DeliveryPolicy::fromArray(...)),
            payment: NestedShape::object($data, 'payment', PaymentPolicy::fromArray(...)),
            bundle: NestedShape::object($data, 'bundle', BundlePolicy::fromArray(...)),
        );
    }
}
