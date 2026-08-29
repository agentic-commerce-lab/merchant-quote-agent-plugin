<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

use MerchantQuoteAgentPlugin\Policy\Data\OfferedDelivery;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPayment;
use MerchantQuoteAgentPlugin\Policy\Data\OfferedPrice;
use MerchantQuoteAgentPlugin\Policy\Data\PaymentTerm;
use MerchantQuoteAgentPlugin\Policy\Data\ProposedOffer;
use MerchantQuoteAgentPlugin\Policy\Data\QuoteLinePrice;

/**
 * The concession terms of a negotiate response — everything ProposedOffer
 * needs except the order total, which only the caller (Servicing) knows.
 * Split out of NegotiateResponse so that class's own constructor stays
 * under the parameter-count gate; this is where the term-by-term parsing lives.
 */
final readonly class OfferTerms
{
    /** @param list<QuoteLinePrice>|null $linePrices */
    private function __construct(
        private ?float $discountPercent,
        private ?array $linePrices,
        private OfferedDelivery $delivery,
        private OfferedPayment $payment,
    ) {}

    /** @param array<string, mixed> $raw */
    public static function read(array $raw): self
    {
        return new self(
            discountPercent: Scalar::float($raw, 'discount_percent'),
            linePrices: self::linePrices($raw),
            delivery: new OfferedDelivery(
                freeShipping: Scalar::bool($raw, 'free_shipping'),
                expedited: Scalar::bool($raw, 'expedited'),
                committedLeadTimeDays: Scalar::int($raw, 'committed_lead_time_days'),
            ),
            payment: new OfferedPayment(
                paymentTerm: self::term($raw),
                netDays: Scalar::int($raw, 'net_days'),
                depositPercent: Scalar::float($raw, 'deposit_percent'),
            ),
        );
    }

    /**
     * Both a quote-wide discount and per-line prices. OfferApplier writes the
     * lines and silently drops the discount, so the offer the buyer is told
     * about would not be the offer the database holds. Unusable, not a choice.
     */
    public function contradictory(): bool
    {
        return $this->discountPercent !== null && $this->linePrices !== null;
    }

    public function toOffer(float $orderTotalNet): ProposedOffer
    {
        return new ProposedOffer(
            orderTotalNet: $orderTotalNet,
            price: new OfferedPrice(discountPercent: $this->discountPercent, linePricesNet: $this->linePrices),
            delivery: $this->delivery,
            payment: $this->payment,
        );
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @return list<QuoteLinePrice>|null
     */
    private static function linePrices(array $raw): ?array
    {
        $prices = [];

        foreach (Json::rows($raw, 'line_prices') as $row) {
            $id = $row['line_item_id'] ?? null;
            $price = $row['unit_price_net'] ?? null;

            if (\is_string($id) && (\is_int($price) || \is_float($price))) {
                $prices[] = new QuoteLinePrice($id, (float) $price);
            }
        }

        return $prices === [] ? null : $prices;
    }

    /** @param array<string, mixed> $raw */
    private static function term(array $raw): ?PaymentTerm
    {
        $term = $raw['payment_term'] ?? null;

        return \is_string($term) ? PaymentTerm::tryFrom($term) : null;
    }
}
