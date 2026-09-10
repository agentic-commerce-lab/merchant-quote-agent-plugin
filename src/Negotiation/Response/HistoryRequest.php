<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Negotiation\Response;

/**
 * The model's request for account history.
 *
 * Shaped exactly like OfferTerms — a non-nullable nested object with nullable
 * fields and a default instance — and for a measured reason: nullable SCALARS
 * are proven to work against this plugin's providers (`escalationReason` is
 * one), a nullable nested OBJECT is not. Copying the proven shape costs one
 * `isSet()` and buys no new provider risk.
 *
 * `productId` is the only model-supplied value in this whole feature that
 * reaches a read. HistoryRequestResolver allow-lists it against the quote's own
 * lines before it gets there.
 */
final readonly class HistoryRequest
{
    public function __construct(
        public ?HistoryRequestKind $kind = null,
        public ?string $productId = null,
    ) {}

    public function isSet(): bool
    {
        return $this->kind !== null;
    }
}
