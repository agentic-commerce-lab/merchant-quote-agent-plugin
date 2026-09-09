<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Integration;

use MerchantQuoteAgentPlugin\Bridge\SalesChannelContextResolver;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Ucp\Sdk\Model\RequestContext;

/**
 * Resolved `SalesChannelContext`s for `BuyerQuoteFlowTest`'s customers.
 *
 * Separate from `BuyerQuoteFlowTest` so that class stays under mago's
 * too-many-methods ceiling, and out of `BuyerQuoteFixture` too — that one
 * already holds eight statics plus its private `connection()`, so three more
 * would just move the finding onto it instead of clearing it.
 */
final class BuyerQuoteContextFixture
{
    private function __construct() {}

    public static function buyerContext(ContainerInterface $container): SalesChannelContext
    {
        return self::contextFor($container, BuyerQuoteFixture::anyQuoteCapableCustomerId($container));
    }

    public static function buyerContextWithoutQuoteFeature(ContainerInterface $container): SalesChannelContext
    {
        return self::contextFor($container, BuyerQuoteFixture::anyCustomerWithoutQuoteFeature($container));
    }

    /**
     * Same as {@see buyerContext()} but for a specific customer id, for tests
     * that need a particular customer's tax mode or customer group rather
     * than whichever quote-capable customer the shop happens to have first.
     */
    public static function contextForCustomer(ContainerInterface $container, string $customerId): SalesChannelContext
    {
        return self::contextFor($container, $customerId);
    }

    private static function contextFor(ContainerInterface $container, string $customerId): SalesChannelContext
    {
        $resolver = $container->get(SalesChannelContextResolver::class);

        if (!$resolver instanceof SalesChannelContextResolver) {
            throw new \RuntimeException('The container has no SalesChannelContextResolver.');
        }

        return $resolver->resolveForCustomer(
            $customerId,
            new RequestContext(BuyerQuoteFixture::storefrontHost($container)),
        );
    }
}
