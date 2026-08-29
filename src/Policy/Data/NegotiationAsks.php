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
     *
     * `false` is the model saying no; `0` is the buyer asking for something.
     * Plain truthiness cannot tell those apart, and read them the same way it
     * dropped `requested_net_days: 0` (pay on delivery), `shipping_cost_net:
     * 0` (free shipping) and `requested_deposit_percent: 0` — answering the
     * price half in silence, which is the failure this gate exists to stop.
     */
    public function hasAny(): bool
    {
        $stated = static fn(mixed $v): bool => $v !== null && $v !== false;

        foreach ([$this->delivery, $this->payment, $this->bundle] as $ask) {
            if ($ask !== null && array_filter(get_object_vars($ask), $stated) !== []) {
                return true;
            }
        }

        return false;
    }
}
