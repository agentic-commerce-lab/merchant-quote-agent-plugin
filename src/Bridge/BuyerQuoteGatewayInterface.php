<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteList;
use MerchantQuoteAgentPlugin\Ucp\Quote\QuoteSnapshot;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Exception\ResourceNotFoundException;
use Ucp\Sdk\Exception\ValidationException;

/**
 * Buyer-facing B2B quote operations, in an already-authenticated customer's
 * sales-channel context.
 *
 * The context is a parameter rather than a credential on purpose:
 * authentication belongs to the transport, and this port's business is
 * Shopware. That is what keeps Bridge free of any dependency on Identity.
 *
 * The counterpart to {@see QuoteGatewayInterface}, which is the merchant side
 * of the same backend: that one writes an existing quote in an admin context
 * for the servicing loop, this one acts as the customer.
 */
interface BuyerQuoteGatewayInterface
{
    /** Commercial backend installed and quote management licensed. */
    public function isAvailable(): bool;

    /**
     * Two steps in Shopware: the line items become a draft quote, which is
     * then sent, moving it to `open`.
     *
     * @param list<array{product_id?: string, quantity?: int, requested_unit_price?: float|int|string}> $lineItems
     * @throws ValidationException
     */
    public function requestQuote(SalesChannelContext $context, array $lineItems, ?string $comment): QuoteSnapshot;

    /** @throws ResourceNotFoundException an unknown id and a foreign one are indistinguishable, by contract */
    public function getQuote(SalesChannelContext $context, string $quoteId): QuoteSnapshot;

    /** The customer's own quotes, newest first. */
    public function listQuotes(SalesChannelContext $context, int $limit, int $page): QuoteList;

    /**
     * Counter-offer: new per-unit asks and/or a comment. Valid from `replied`.
     *
     * @param list<array{id?: string, product_id?: string, requested_unit_price?: float|int|string}> $lineItems
     * @throws ResourceNotFoundException|ValidationException
     */
    public function counterQuote(
        SalesChannelContext $context,
        string $quoteId,
        array $lineItems,
        ?string $comment,
    ): QuoteSnapshot;

    /**
     * Accepting is ordering in Shopware's model; the snapshot carries the
     * resulting order reference.
     *
     * @throws ResourceNotFoundException|ValidationException
     */
    public function acceptQuote(SalesChannelContext $context, string $quoteId): QuoteSnapshot;

    /** @throws ResourceNotFoundException|ValidationException */
    public function declineQuote(SalesChannelContext $context, string $quoteId, ?string $comment): QuoteSnapshot;
}
