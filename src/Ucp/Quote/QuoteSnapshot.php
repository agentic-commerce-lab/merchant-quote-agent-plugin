<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Ucp\Quote;

/**
 * Transparent view of a quote as published to buyer agents.
 *
 * Price semantics are part of the published contract: all amounts are in
 * `currency`, `taxStatus` says whether the gross or the net total is the
 * customer-facing authoritative amount, and every line item price is per unit.
 */
final class QuoteSnapshot
{
    /**
     * @mago-expect lint:excessive-parameter-list
     * A data carrier's promoted properties ARE its interface, and every
     * construction site uses named arguments (see CommercialQuoteSnapshotMapper
     * and SwagCommercialBuyerQuoteGateway::acceptQuote()), so the call-site
     * complexity this rule exists to catch does not arise here.
     *
     * @param list<array{id: string, product_id: string|null, label: string, quantity: int, unit_price: float, total_price: float, requested_unit_price: float|null}> $lineItems
     * @param list<array{comment: string, author: string, created_at: string|null}>                                                                                   $comments
     */
    public function __construct(
        public readonly string $id,
        public readonly string $quoteNumber,
        public readonly ?string $state,
        public readonly ?string $expirationDate,
        public readonly ?string $currency,
        public readonly ?float $totalGross,
        public readonly ?float $totalNet,
        public readonly ?string $taxStatus,
        public readonly array $lineItems,
        public readonly array $comments,
        public readonly ?string $orderId = null,
        public readonly ?string $orderNumber = null,
        public readonly ?string $a2cnSessionId = null,
    ) {}

    /**
     * A copy with the order reference attached, for acceptQuote() to report
     * the order it just placed without rebuilding every field by hand and
     * risking one dropped in the transposition.
     */
    public function withOrder(string $orderId, ?string $orderNumber): self
    {
        return new self(
            id: $this->id,
            quoteNumber: $this->quoteNumber,
            state: $this->state,
            expirationDate: $this->expirationDate,
            currency: $this->currency,
            totalGross: $this->totalGross,
            totalNet: $this->totalNet,
            taxStatus: $this->taxStatus,
            lineItems: $this->lineItems,
            comments: $this->comments,
            orderId: $orderId,
            orderNumber: $orderNumber,
            a2cnSessionId: $this->a2cnSessionId,
        );
    }

    /**
     * A copy with the derived A2CN session id attached, for A2cnSessionStamp
     * to report the id it just persisted without rebuilding every field by
     * hand and risking one dropped in the transposition.
     */
    public function withA2cnSession(string $sessionId): self
    {
        return new self(
            id: $this->id,
            quoteNumber: $this->quoteNumber,
            state: $this->state,
            expirationDate: $this->expirationDate,
            currency: $this->currency,
            totalGross: $this->totalGross,
            totalNet: $this->totalNet,
            taxStatus: $this->taxStatus,
            lineItems: $this->lineItems,
            comments: $this->comments,
            orderId: $this->orderId,
            orderNumber: $this->orderNumber,
            a2cnSessionId: $sessionId,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'id' => $this->id,
            'quote_number' => $this->quoteNumber,
            'state' => $this->state,
            // Always present, even when null: agents must be able to read it to act
            // before the offer expires.
            'expiration_date' => $this->expirationDate,
            'currency' => $this->currency,
            'totals' => [
                'gross' => $this->totalGross,
                'net' => $this->totalNet,
                'tax_status' => $this->taxStatus,
            ],
            'line_items' => $this->lineItems,
            'comments' => $this->comments,
        ];

        if (null !== $this->orderId) {
            $payload['order'] = [
                'id' => $this->orderId,
                'order_number' => $this->orderNumber,
            ];
        }

        if (null !== $this->a2cnSessionId) {
            $payload['a2cn_session_id'] = $this->a2cnSessionId;
        }

        return $payload;
    }
}
