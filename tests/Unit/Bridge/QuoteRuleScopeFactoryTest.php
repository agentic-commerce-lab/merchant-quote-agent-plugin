<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

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
        );

        $this->expectException(RuleScopeUnavailable::class);

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
        );

        $this->expectException(RuleScopeUnavailable::class);

        $factory->forQuote(self::QUOTE_ID, Context::createDefaultContext());
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
