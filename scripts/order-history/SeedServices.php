<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Scripts\OrderHistory;

use MerchantQuoteAgentPlugin\Bridge\AgentContext;
use MerchantQuoteAgentPlugin\Bridge\BuyerQuoteGatewayInterface;
use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialAvailability;
use MerchantQuoteAgentPlugin\Bridge\QuoteStateTransitioner;
use MerchantQuoteAgentPlugin\Bridge\SalesChannelContextResolver;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\ServiceLocator;
use Ucp\Sdk\Model\RequestContext;

/** Typed access to the narrow dev/test-only locator and core repositories. */
final readonly class SeedServices
{
    private ServiceLocator $locator;

    public function __construct(
        private ContainerInterface $container,
    ) {
        $locator = $container->get('merchant_quote_agent.dev.order_history');
        if (!$locator instanceof ServiceLocator) {
            throw new \RuntimeException('The dev/test order-history service locator is unavailable.');
        }
        $this->locator = $locator;
    }

    /** @throws \Psr\Container\ContainerExceptionInterface */
    public function buyer(): BuyerQuoteGatewayInterface
    {
        $buyer = $this->locator->get(BuyerQuoteGatewayInterface::class);
        if (!$buyer instanceof BuyerQuoteGatewayInterface) {
            throw new \RuntimeException('The buyer quote gateway is unavailable.');
        }
        return $buyer;
    }

    /** @throws \Psr\Container\ContainerExceptionInterface */
    public function transitioner(): QuoteStateTransitioner
    {
        $transitioner = $this->locator->get(QuoteStateTransitioner::class);
        if (!$transitioner instanceof QuoteStateTransitioner) {
            throw new \RuntimeException('The quote transitioner is unavailable.');
        }
        return $transitioner;
    }

    /** @throws \Psr\Container\ContainerExceptionInterface */
    public function route(): object
    {
        $route = $this->locator->get(CommercialAvailability::QUOTE_ORDER_ROUTE);
        if (!is_object($route) || !method_exists($route, 'order')) {
            throw new \RuntimeException('The Commercial quote order route is unavailable.');
        }
        return $route;
    }

    /**
     * @param array{id: string, channel: string, host: string} $customer
     * @throws \Psr\Container\ContainerExceptionInterface
     * @throws \Doctrine\DBAL\Exception
     */
    public function context(array $customer): SalesChannelContext
    {
        $resolver = $this->locator->get(SalesChannelContextResolver::class);
        if (!$resolver instanceof SalesChannelContextResolver) {
            throw new \RuntimeException('The sales-channel context resolver is unavailable.');
        }
        $context = $resolver->resolveForCustomer($customer['id'], new RequestContext($customer['host']));
        if (
            $context->getSalesChannelId() !== $customer['channel']
            || $context->getCustomer()?->getId() !== $customer['id']
        ) {
            throw new \RuntimeException('The resolved customer/channel differs from the live quote customer.');
        }
        $context->getContext()->addState(AgentContext::STATE, Context::SKIP_TRIGGER_FLOW);
        return $context;
    }

    public static function systemContext(): Context
    {
        $context = AgentContext::create();
        $context->addState(Context::SKIP_TRIGGER_FLOW);
        return $context;
    }

    /** @return EntityRepository<covariant \Shopware\Core\Framework\DataAbstractionLayer\EntityCollection> */
    public function repository(string $id): EntityRepository
    {
        $repository = $this->container->get($id);
        if (!$repository instanceof EntityRepository) {
            throw new \RuntimeException('Missing repository: ' . $id);
        }
        return $repository;
    }
}
