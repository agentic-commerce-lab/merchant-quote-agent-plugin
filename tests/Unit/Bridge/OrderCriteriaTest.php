<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\History\CustomerScope;
use MerchantQuoteAgentPlugin\Bridge\History\OrderHistoryReads;
use MerchantQuoteAgentPlugin\Bridge\QuoteVersionResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\EntityCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Bucket\TermsAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\CountAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\MaxAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Aggregation\Metric\SumAggregation;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\EntitySearchResult;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;

/**
 * Asserts the CRITERIA OrderHistoryReads builds, not the (mocked) data it gets
 * back. The field path is the security-relevant surface: CustomerScope::verify()
 * only catches a wrong path AFTER a row has already been fetched from the
 * wrong company. This proves the path we send is the one we mean to send —
 * tests/Integration/ (Task 6) proves it resolves against a real shop.
 */
final class OrderCriteriaTest extends TestCase
{
    public function testHistoryFiltersByOrderCustomerAndAssociatesItForVerification(): void
    {
        $criteria = $this->captureOrderCriteria(
            fn(OrderHistoryReads $reads, CustomerScope $scope) => $reads->history($scope),
        );

        $this->assertScopedTo($criteria, 'orderCustomer.customerId');
        self::assertTrue($criteria->hasAssociation('orderCustomer'), 'orderCustomer must be associated for verify().');
        self::assertTrue($criteria->hasAssociation('stateMachineState'));
        self::assertTrue($criteria->hasAssociation('currency'));
        self::assertTrue($criteria->hasAssociation('lineItems'), 'history() renders line detail.');
    }

    public function testStatsRunsTheSameSearchWithoutLineItems(): void
    {
        $criteria = $this->captureOrderCriteria(
            fn(OrderHistoryReads $reads, CustomerScope $scope) => $reads->stats($scope),
        );

        $this->assertScopedTo($criteria, 'orderCustomer.customerId');
        self::assertFalse($criteria->hasAssociation('lineItems'), 'stats() has no use for line detail.');
        self::assertSame(1, $criteria->getLimit(), 'The page size does not limit customer-scoped aggregations.');
    }

    public function testOrderSearchSortsByNewestFirstAndCapsAtTen(): void
    {
        $criteria = $this->captureOrderCriteria(
            fn(OrderHistoryReads $reads, CustomerScope $scope) => $reads->history($scope),
        );

        $sortings = $criteria->getSorting();
        self::assertCount(1, $sortings);
        self::assertSame('orderDateTime', $sortings[0]->getField());
        self::assertSame(FieldSorting::DESCENDING, $sortings[0]->getDirection());
        self::assertSame(10, $criteria->getLimit());
    }

    public function testOrderSearchCarriesUnboundedCurrencyAndMoneyAggregations(): void
    {
        $criteria = $this->captureOrderCriteria(
            fn(OrderHistoryReads $reads, CustomerScope $scope) => $reads->history($scope),
        );

        // Keyed by aggregation name, not a numeric list (Criteria::addAggregation).
        $aggregations = $criteria->getAggregations();
        self::assertCount(4, $aggregations);
        self::assertInstanceOf(TermsAggregation::class, $aggregations['currencies']);
        self::assertSame('currency.isoCode', $aggregations['currencies']->getField());
        self::assertNull($aggregations['currencies']->getLimit());

        self::assertInstanceOf(CountAggregation::class, $aggregations['orderCount']);
        self::assertSame('id', $aggregations['orderCount']->getField());

        self::assertInstanceOf(SumAggregation::class, $aggregations['lifetimeNet']);
        self::assertSame('amountNet', $aggregations['lifetimeNet']->getField());

        self::assertInstanceOf(MaxAggregation::class, $aggregations['lastOrderAt']);
        self::assertSame('orderDateTime', $aggregations['lastOrderAt']->getField());
    }

    public function testOrderSearchUsesTheLiveVersionContext(): void
    {
        $seenContext = null;
        $orders = $this->createMock(EntityRepository::class);
        $orders
            ->method('search')
            ->willReturnCallback(function (Criteria $criteria, $context) use (&$seenContext) {
                $seenContext = $context;

                return $this->emptyResult();
            });

        (new OrderHistoryReads($orders, $this->createMock(EntityRepository::class)))->history($this->scope());

        self::assertNotNull($seenContext);
        self::assertSame(Defaults::LIVE_VERSION, $seenContext->getVersionId());
    }

    public function testPurchasesOfFiltersByTheTwoHopCustomerPathAndByProduct(): void
    {
        $criteria = null;
        $orderLines = $this->createMock(EntityRepository::class);
        $orderLines
            ->method('search')
            ->willReturnCallback(function (Criteria $c) use (&$criteria) {
                $criteria = $c;

                return $this->emptyResult();
            });

        (new OrderHistoryReads($this->createMock(EntityRepository::class), $orderLines))->purchasesOf(
            $this->scope(),
            'product-1',
        );

        self::assertNotNull($criteria);
        $this->assertScopedTo($criteria, 'order.orderCustomer.customerId');

        self::assertTrue($criteria->hasAssociation('order'), 'order.orderCustomer must be associated for verify().');
        self::assertTrue($criteria->getAssociation('order')->hasAssociation('orderCustomer'));
        self::assertTrue($criteria->getAssociation('order')->hasAssociation('currency'));

        $filters = $criteria->getFilters();
        $productFilter = null;
        foreach ($filters as $filter) {
            if ($filter instanceof EqualsFilter && $filter->getField() === 'productId') {
                $productFilter = $filter;
            }
        }
        self::assertNotNull($productFilter, 'productId must be filtered.');
        self::assertSame('product-1', $productFilter->getValue());

        $sortings = $criteria->getSorting();
        self::assertCount(1, $sortings);
        self::assertSame('order.orderDateTime', $sortings[0]->getField());
        self::assertSame(FieldSorting::DESCENDING, $sortings[0]->getDirection());
        self::assertSame(10, $criteria->getLimit());
    }

    private function assertScopedTo(Criteria $criteria, string $expectedField): void
    {
        $filters = $criteria->getFilters();
        $scopeFilter = null;

        foreach ($filters as $filter) {
            if ($filter instanceof EqualsFilter && $filter->getField() === $expectedField) {
                $scopeFilter = $filter;
            }
        }

        self::assertNotNull($scopeFilter, "Criteria must filter on {$expectedField}.");
        self::assertSame('customer-1', $scopeFilter->getValue());
    }

    /** @param callable(OrderHistoryReads, CustomerScope): mixed $call */
    private function captureOrderCriteria(callable $call): Criteria
    {
        $criteria = null;
        $orders = $this->createMock(EntityRepository::class);
        $orders
            ->method('search')
            ->willReturnCallback(function (Criteria $c) use (&$criteria) {
                $criteria = $c;

                return $this->emptyResult();
            });

        $call(new OrderHistoryReads($orders, $this->createMock(EntityRepository::class)), $this->scope());

        self::assertNotNull($criteria);

        return $criteria;
    }

    private function scope(): CustomerScope
    {
        return new CustomerScope('customer-1', '', new QuoteVersionResolver());
    }

    /** @return EntitySearchResult<covariant EntityCollection> */
    private function emptyResult(): EntitySearchResult
    {
        return new EntitySearchResult(
            'order',
            0,
            new EntityCollection([]),
            null,
            new Criteria(),
            $this->scope()->context(),
        );
    }
}
