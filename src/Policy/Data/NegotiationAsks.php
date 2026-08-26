<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

final readonly class NegotiationAsks
{
    public function __construct(
        public ?DeliveryAsk $delivery = null,
        public ?PaymentAsk $payment = null,
        public ?BundleAsk $bundle = null,
    ) {}

    /** @throws \TypeError|\ValueError */
    public static function fromArray(array $data): self
    {
        return new self(
            delivery: NestedShape::object($data, 'delivery', DeliveryAsk::fromArray(...)),
            payment: NestedShape::object($data, 'payment', PaymentAsk::fromArray(...)),
            bundle: NestedShape::object($data, 'bundle', BundleAsk::fromArray(...)),
        );
    }
}
