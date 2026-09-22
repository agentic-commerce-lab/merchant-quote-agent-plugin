<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\Commercial\CommercialCapabilities;
use MerchantQuoteAgentPlugin\Bridge\QuoteRuleScopeFactory;
use MerchantQuoteAgentPlugin\Bridge\RuleScopeUnavailable;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\Rule\CartRuleScope;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\Entity;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

final class QuoteRuleScopeFactoryTest extends TestCase
{
    private const QUOTE_ID = '0123456789abcdef0123456789abcdef';

    public function testItBuildsACartScopeFromTheRestoredContextAndTheConvertedCart(): void
    {
        $cart = new Cart('token');
        $salesChannelContext = $this->createMock(SalesChannelContext::class);

        $factory = new QuoteRuleScopeFactory(
            new class($salesChannelContext) {
                public function __construct(
                    private readonly SalesChannelContext $context,
                ) {}

                public function restoreByQuote(string $quoteId, Context $context): SalesChannelContext
                {
                    return $this->context;
                }
            },
            new class($cart) {
                public function __construct(
                    private readonly Cart $cart,
                ) {}

                public function convertToCart(object $quote, SalesChannelContext $context): Cart
                {
                    return $this->cart;
                }
            },
            $this->repository([$this->createStub(Entity::class)]),
            CommercialCapabilities::legacy(),
        );

        $scope = $factory->forQuote(self::QUOTE_ID, Context::createDefaultContext());

        self::assertInstanceOf(CartRuleScope::class, $scope);
        self::assertSame($cart, $scope->getCart());
        self::assertSame($salesChannelContext, $scope->getSalesChannelContext());
    }

    /**
     * The real failure this guards: a customer with no active shipping address
     * makes QuoteToCartConverter throw. The rung is skipped, so the throw has
     * to arrive as this plugin's own exception rather than SwagCommercial's,
     * which the resolver cannot name under ADR 0001.
     */
    public function testAConversionFailureBecomesRuleScopeUnavailable(): void
    {
        $factory = new QuoteRuleScopeFactory(
            new class {
                public function restoreByQuote(string $quoteId, Context $context): SalesChannelContext
                {
                    throw new \RuntimeException('no customer address');
                }
            },
            new class {
                public function convertToCart(object $quote, SalesChannelContext $context): never
                {
                    throw new \LogicException('unreachable');
                }
            },
            $this->repository([$this->createStub(Entity::class)]),
            CommercialCapabilities::legacy(),
        );

        $this->expectException(RuleScopeUnavailable::class);
        $this->expectExceptionMessage('could not be converted to a cart');

        $factory->forQuote(self::QUOTE_ID, Context::createDefaultContext());
    }

    public function testAMissingQuoteRowBecomesRuleScopeUnavailable(): void
    {
        $factory = new QuoteRuleScopeFactory(
            new class {
                public function restoreByQuote(string $quoteId, Context $context): never
                {
                    throw new \LogicException('unreachable');
                }
            },
            new class {
                public function convertToCart(object $quote, SalesChannelContext $context): never
                {
                    throw new \LogicException('unreachable');
                }
            },
            $this->repository([]),
            CommercialCapabilities::legacy(),
        );

        $this->expectException(RuleScopeUnavailable::class);
        $this->expectExceptionMessage('was not found');

        $factory->forQuote(self::QUOTE_ID, Context::createDefaultContext());
    }

    /**
     * The repository double above ignores the Criteria entirely and just hands
     * back whichever fixed entities it was built with, so no assertion built
     * on it can ever prove what the factory asked the database for -- a
     * soft-delete filter or a bare `deliveries` association would look
     * identical to the fix. These three tests instead capture the Criteria
     * the factory builds and assert on THAT, the same technique
     * StrategyResolverTest uses and for the same reason.
     */
    public function testItFiltersSoftDeletedLineItemsWhenTheCapabilityIsPresent(): void
    {
        $criteria = $this->capturedCriteria(CommercialCapabilities::modern());

        $filters = $criteria->getAssociation('lineItems')->getFilters();
        self::assertCount(1, $filters);
        self::assertInstanceOf(EqualsFilter::class, $filters[0]);
        self::assertSame('deletedAt', $filters[0]->getField());
        self::assertNull($filters[0]->getValue());
    }

    /**
     * On released SwagCommercial (through 6.7.12.x) `quote_line_item.deletedAt`
     * does not exist. Filtering on it unconditionally throws
     * UnmappedFieldException and takes rule evaluation down on every one of
     * those shops -- so the legacy path must ask for zero filters, not just
     * "not this one".
     */
    public function testItAddsNoLineItemFilterWhenTheCapabilityIsAbsent(): void
    {
        $criteria = $this->capturedCriteria(CommercialCapabilities::legacy());

        self::assertSame([], $criteria->getAssociation('lineItems')->getFilters());
    }

    public function testItLoadsDeliveriesDeepEnoughForShippingAndPositionRulesToSeeThem(): void
    {
        $criteria = $this->capturedCriteria(CommercialCapabilities::legacy());

        $deliveries = $criteria->getAssociations()['deliveries'] ?? null;
        self::assertInstanceOf(Criteria::class, $deliveries);

        $deliveryAssociations = $deliveries->getAssociations();
        self::assertArrayHasKey('shippingMethod', $deliveryAssociations);
        self::assertArrayHasKey('positions', $deliveryAssociations);
        self::assertArrayHasKey('quoteLineItem', $deliveryAssociations['positions']->getAssociations());
    }

    /**
     * Builds a factory whose repository captures the Criteria it was searched
     * with instead of applying it, then discards the "not found" exception
     * that an empty EntitySearchResult necessarily produces -- these tests are
     * about the query the factory builds, not about what a real repository
     * would hand back for it.
     */
    private function capturedCriteria(CommercialCapabilities $capabilities): Criteria
    {
        $captured = null;

        $quotes = $this->createMock(EntityRepository::class);
        $quotes
            ->method('search')
            ->willReturnCallback(function (Criteria $criteria, Context $context) use (&$captured): EntitySearchResult {
                $captured = $criteria;

                return new EntitySearchResult('quote', 0, new EntityCollection([]), null, $criteria, $context);
            });

        $factory = new QuoteRuleScopeFactory(
            new class {
                public function restoreByQuote(string $quoteId, Context $context): never
                {
                    throw new \LogicException('unreachable');
                }
            },
            new class {
                public function convertToCart(object $quote, SalesChannelContext $context): never
                {
                    throw new \LogicException('unreachable');
                }
            },
            $quotes,
            $capabilities,
        );

        try {
            $factory->forQuote(self::QUOTE_ID, Context::createDefaultContext());
        } catch (RuleScopeUnavailable) {
            // Expected: an empty result means "not found". See the docblock above.
        }

        self::assertInstanceOf(Criteria::class, $captured);

        return $captured;
    }

    /** @param list<object> $entities */
    private function repository(array $entities): EntityRepository
    {
        $repository = $this->createMock(EntityRepository::class);
        $repository
            ->method('search')
            ->willReturnCallback(
                static fn(Criteria $criteria, Context $context): EntitySearchResult => new EntitySearchResult(
                    'quote',
                    \count($entities),
                    new EntityCollection($entities),
                    null,
                    $criteria,
                    $context,
                ),
            );

        return $repository;
    }
}
