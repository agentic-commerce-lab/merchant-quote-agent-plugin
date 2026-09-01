<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Bridge;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Ucp\Sdk\Exception\ConfigurationException;
use Ucp\Sdk\Model\RequestContext;

/**
 * Which shop a UCP request landed on, and the Shopware context of a customer
 * acting there.
 *
 * A port rather than a bare class so the resource-server side can be unit
 * tested without a booted kernel: everything behind it is Shopware
 * infrastructure.
 */
interface CustomerContextResolverInterface
{
    /** @throws ConfigurationException no active sales channel serves the request's host */
    public function resolveSalesChannel(RequestContext $context): SalesChannelResolution;

    /**
     * Materialises the customer's own context, so contract prices, customer
     * group and rules apply exactly as they would if the customer acted. The
     * caller must already have proven the authorization.
     *
     * Pass `$resolution` when the caller already resolved the sales channel
     * (e.g. to read the request's host); otherwise it is resolved again here.
     *
     * @throws ConfigurationException
     */
    public function resolveForCustomer(
        string $customerId,
        RequestContext $context,
        ?SalesChannelResolution $resolution = null,
    ): SalesChannelContext;
}
