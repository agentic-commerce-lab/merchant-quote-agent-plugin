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
}
