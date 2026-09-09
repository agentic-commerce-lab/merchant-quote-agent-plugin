<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\History\CrossCustomerRead;
use MerchantQuoteAgentPlugin\Bridge\History\CustomerScope;
use MerchantQuoteAgentPlugin\Bridge\QuoteVersionResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;

/**
 * The customer boundary. Two independent properties have to fail before a pass
 * can see another company's data, and both are asserted here:
 *
 *  1. Every Criteria carries the bound customer filter and the live version.
 *  2. Any row whose customer id is not the bound one throws.
 *
 * The field PATH is a caller-supplied constant ('customerId',
 * 'orderCustomer.customerId', ...) because it differs per entity. The VALUE is
 * always the bound id, and nothing can pass a different one.
 */
final class CustomerScopeTest extends TestCase
{
    private const COMPANY = '0199aa0000004000800000000000c0de';

    private const OTHER = '0199bb0000004000800000000000beef';

    private static function scope(string $customerId = self::COMPANY): CustomerScope
    {
        return new CustomerScope($customerId, new QuoteVersionResolver());
    }

    public function testEveryCriteriaCarriesTheBoundCustomerFilter(): void
    {
        $criteria = self::scope()->criteria('orderCustomer.customerId');

        $filters = $criteria->getFilters();
        self::assertCount(1, $filters);
        $filter = $filters[0];
        self::assertInstanceOf(EqualsFilter::class, $filter);
        self::assertSame('orderCustomer.customerId', $filter->getField());
        self::assertSame(self::COMPANY, $filter->getValue());
    }

    public function testTheContextIsAlwaysTheLiveVersion(): void
    {
        // The quote table and the order table are both versioned. Counting rows
        // instead of live rows doubles a buyer's apparent history: the dev shop
        // has ~75 quote rows behind ~37 live quotes.
        self::assertSame(Defaults::LIVE_VERSION, self::scope()->context()->getVersionId());
    }

    public function testAMatchingRowVerifiesQuietly(): void
    {
        self::scope()->verify(self::COMPANY, 'quote 10001');

        self::assertTrue(true, 'verify() returns void; reaching here is the assertion.');
    }

    public function testAForeignRowThrows(): void
    {
        $this->expectException(CrossCustomerRead::class);
        $this->expectExceptionMessageMatches('/quote 10001/');

        self::scope()->verify(self::OTHER, 'quote 10001');
    }

    public function testARowWithNoCustomerAtAllThrows(): void
    {
        // A missing association reads as null, and null must not pass as "fine".
        $this->expectException(CrossCustomerRead::class);

        self::scope()->verify(null, 'order 3001');
    }

    public function testTheExceptionMessageCarriesNeitherCustomerId(): void
    {
        // This lands in a merchant-readable audit column, and one of the two ids
        // belongs to a company that is not party to this quote.
        try {
            self::scope()->verify(self::OTHER, 'quote 10001');
        } catch (CrossCustomerRead $e) {
            self::assertStringNotContainsString(self::OTHER, $e->getMessage());
            self::assertStringNotContainsString(self::COMPANY, $e->getMessage());

            return;
        }

        self::fail('Expected CrossCustomerRead.');
    }

    public function testAnEmptyCustomerIdIsReportedAsEmpty(): void
    {
        self::assertTrue(self::scope('')->isEmpty());
        self::assertFalse(self::scope()->isEmpty());
    }

    public function testAnEmptyScopeStillFiltersRatherThanMatchingEverything(): void
    {
        // The safety property behind isEmpty(): even if a caller ignored it, the
        // filter is still applied and matches nothing. There is no code path
        // that produces an unfiltered Criteria.
        $filters = self::scope('')->criteria('customerId')->getFilters();

        self::assertCount(1, $filters);
        self::assertInstanceOf(EqualsFilter::class, $filters[0]);
        self::assertSame('', $filters[0]->getValue());
    }
}
