<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Policy\Data;

/**
 * The asks that are not about price: delivery and payment terms.
 *
 * A volume ask ("better price if we take 10?") deliberately does NOT live
 * here. It used to, as `BundleAsk::$requested`, and that put a pure price ask
 * on the escalating side of AskGate: quote 1053 asked "Can we get some better
 * price, as we take 10?", the extraction set `bestPriceRequested` AND the
 * bundle flag, and the flag escalated a round the pricing policy could answer
 * on its own. Volume/bulk/tiered asks are `price.bestPriceRequested` now, and
 * the extract prompt routes them there; a buyer asking for a free EXTRA
 * product is `structural.addProducts`, which escalates on its own merits.
 */
final readonly class NegotiationAsks
{
    public function __construct(
        public ?DeliveryAsk $delivery = null,
        public ?PaymentAsk $payment = null,
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

        foreach ([$this->delivery, $this->payment] as $ask) {
            if ($ask !== null && array_filter(get_object_vars($ask), $stated) !== []) {
                return true;
            }
        }

        return false;
    }
}
