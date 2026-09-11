<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Ucp\Quote;

use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use Override;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Contract\CapabilityInterface;
use Ucp\Sdk\Exception\UnsupportedCapabilityException;
use Ucp\Sdk\Model\Profile\CapabilityDescriptor;

/**
 * Registers `com.shopware.quote` with the UCP SDK and carries its six
 * buyer-facing operations.
 *
 * Implementing CapabilityInterface is all the registration takes: the SDK
 * autoconfigures the `ucp_sdk.capability` tag onto every implementation and
 * collects them into its CapabilityRegistry. No decoration of the Agentic
 * Commerce plugin, and no changes to it.
 *
 * Guard-then-delegate: the backend is optional, so without SwagCommercial the
 * gateway is absent and every operation fails as unsupported (501) rather than
 * as a container error. The descriptor is still published — the profile
 * contributor asks only whether identity linking is enabled, never whether the
 * commercial backend is there — because the contract documents are served
 * either way, and the published schema says so: a 501, not a missing
 * descriptor, is what tells an agent this shop cannot quote.
 */
final class QuoteCapability implements CapabilityInterface
{
    public function __construct(
        private readonly ?BuyerQuoteGatewayInterface $gateway = null,
    ) {}

    #[Override]
    public function describe(): CapabilityDescriptor
    {
        return QuoteCapabilityDescriptor::paths();
    }

    /**
     * @param list<array{product_id?: string, quantity?: int, requested_unit_price?: float|int|string}> $lineItems
     */
    public function requestQuote(SalesChannelContext $context, array $lineItems, ?string $comment): QuoteSnapshot
    {
        return $this->gateway()->requestQuote($context, $lineItems, $comment);
    }

    public function getQuote(SalesChannelContext $context, string $quoteId): QuoteSnapshot
    {
        return $this->gateway()->getQuote($context, $quoteId);
    }

    public function listQuotes(SalesChannelContext $context, int $limit, int $page): QuoteList
    {
        return $this->gateway()->listQuotes($context, $limit, $page);
    }

    /**
     * @param list<array{id?: string, product_id?: string, requested_unit_price?: float|int|string}> $lineItems
     */
    public function counterQuote(
        SalesChannelContext $context,
        string $quoteId,
        array $lineItems,
        ?string $comment,
    ): QuoteSnapshot {
        return $this->gateway()->counterQuote($context, $quoteId, $lineItems, $comment);
    }

    public function acceptQuote(SalesChannelContext $context, string $quoteId): QuoteSnapshot
    {
        return $this->gateway()->acceptQuote($context, $quoteId);
    }

    public function declineQuote(SalesChannelContext $context, string $quoteId, ?string $comment): QuoteSnapshot
    {
        return $this->gateway()->declineQuote($context, $quoteId, $comment);
    }

    private function gateway(): BuyerQuoteGatewayInterface
    {
        if ($this->gateway === null || !$this->gateway->isAvailable()) {
            throw new UnsupportedCapabilityException(
                'The quote capability requires a licensed commercial quote backend.',
            );
        }

        return $this->gateway;
    }
}
