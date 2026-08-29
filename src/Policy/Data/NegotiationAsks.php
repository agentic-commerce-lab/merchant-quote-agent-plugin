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

    /**
     * True when a sub-ask actually carries an ask. The extract prompt says to
     * set the whole object to null when the buyer asked for nothing, but a
     * model happily emits the full shape with `false` and nulls in it — and
     * every field here is optional — so a present sub-object is not an ask.
     */
    public function hasAny(): bool
    {
        foreach ([$this->delivery, $this->payment, $this->bundle] as $ask) {
            if ($ask !== null && array_filter(get_object_vars($ask)) !== []) {
                return true;
            }
        }

        return false;
    }
}
