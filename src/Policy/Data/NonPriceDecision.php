<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

/**
 * The delivery/payment/bundle dimensions only — decideNegotiation composes
 * this on top of the price decision from QuoteDecider.
 */
final readonly class NonPriceDecision
{
    /** @param list<string> $escalationReasons */
    public function __construct(
        public Band $band,
        public ?DeliveryDecision $delivery = null,
        public ?PaymentDecision $payment = null,
        public ?BundleDecision $bundle = null,
        public array $escalationReasons = [],
    ) {}
}
