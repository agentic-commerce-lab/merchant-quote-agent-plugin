<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Assistant;

use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Config\QuoteAgentSettingsReader;
use Override;
use Shopware\Core\PlatformRequest;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolContext;
use Swag\AssistantStarterKit\Core\Tool\Factory\ToolFactoryInterface;
use Symfony\Component\HttpFoundation\RequestStack;

final readonly class RequestQuoteToolFactory implements ToolFactoryInterface
{
    public function __construct(
        private RequestStack $requestStack,
        private QuoteAgentSettingsReader $settings,
        private ?BuyerQuoteGatewayInterface $gateway = null,
    ) {}

    /**
     * Null rather than a disabled tool, which is the starter kit's own rule
     * (D6) and ours: a tool that is never constructed never reaches the schema
     * the model sees, so it cannot be talked into using one.
     *
     * The sales-channel context comes from the request rather than from
     * ToolContext, which carries only a trace and the merchant's settings and
     * must stay that way — upstream asserts its property list.
     */
    #[Override]
    public function create(ToolContext $context): ?object
    {
        $request = $this->requestStack->getMainRequest();
        $salesChannelContext = $request?->attributes->get(PlatformRequest::ATTRIBUTE_SALES_CHANNEL_CONTEXT_OBJECT);

        if (!$salesChannelContext instanceof SalesChannelContext) {
            return null;
        }

        if (null === $this->gateway || !$this->gateway->isAvailable()) {
            return null;
        }

        if (!$this->settings->assistantQuoteRequests($salesChannelContext->getSalesChannelId())) {
            return null;
        }

        // A guest has no quotes and no B2B employee behind them. Asking the
        // gateway would raise; returning null keeps the tool off the schema,
        // so the model offers a quote only to someone who could get one.
        if (null === $salesChannelContext->getCustomer()) {
            return null;
        }

        return new RequestQuoteTool($this->gateway, $salesChannelContext);
    }
}
