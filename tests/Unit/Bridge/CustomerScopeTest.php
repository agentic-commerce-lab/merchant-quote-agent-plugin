<?php

declare(strict_types=1);

namespace MerchantQuoteAgentPlugin\Tests\Unit\Bridge;

use MerchantQuoteAgentPlugin\Bridge\History\CrossCustomerRead;
use MerchantQuoteAgentPlugin\Bridge\History\CustomerScope;
use MerchantQuoteAgentPlugin\Bridge\QuoteVersionResolver;
use PHPUnit\Framework\TestCase;
use Shopware\Core\Defaults;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\NotFilter;

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
 *
 * @mago-expect lint:too-many-methods
 * One test per property of the boundary, and the boundary is the whole point:
 * an unfiltered Criteria, a loose comparison, a leaked id in the message, or a
 * quote appearing in its own history are four different failures and each
 * needs its own name in the output. Splitting the class would hide which
 * property broke behind a file boundary.
 */
final class CustomerScopeTest extends TestCase
{
    private const COMPANY = '0199aa0000004000800000000000c0de';

    private const OTHER = '0199bb0000004000800000000000beef';

    private const THIS_QUOTE = '0199cc0000004000800000000000face';

    private static function scope(string $customerId = self::COMPANY): CustomerScope
    {
        return new CustomerScope($customerId, self::THIS_QUOTE, new QuoteVersionResolver());
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
        // instead of live rows roughly doubles a buyer's apparent history.
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

    public function testAnEmptyScopeStillRejectsAMissingCustomerOnTheRow(): void
    {
        // null !== '' is true (throws, correct); null != '' is false (a loose
        // comparison would let this through as a "match"). An empty bound scope
        // paired with a null $seen is the one case that tells strict and loose
        // comparison apart, and it is exactly the case where a missing
        // association on a customer-less quote must not be waved through as a
        // cross-company read. This test exists to fail the moment verify()'s
        // `!==` is loosened to `!=`.
        $this->expectException(CrossCustomerRead::class);

        self::scope('')->verify(null, 'quote 10001');
    }

    public function testTheQuoteBeingServicedIsExcludedFromItsOwnHistory(): void
    {
        // The buyer's "we already negotiated 15%" problem. With this quote in
        // its own history the model cannot tell a spent precedent on a closed
        // quote from a concession already applied to the total in front of it,
        // and granting it a second time is the double-concession QuoteBaseline
        // (#49) exists to prevent.
        $filters = self::scope()->quoteCriteria()->getFilters();

        self::assertCount(2, $filters, 'The customer filter and the self-exclusion must both be present.');

        $excluded = array_values(array_filter($filters, static fn(object $f): bool => $f instanceof NotFilter));
        self::assertCount(1, $excluded);
        $inner = $excluded[0]->getQueries();
        self::assertCount(1, $inner);
        self::assertInstanceOf(EqualsFilter::class, $inner[0]);
        self::assertSame('id', $inner[0]->getField());
        self::assertSame(self::THIS_QUOTE, $inner[0]->getValue());
    }

    public function testTheQuoteCriteriaStillCarriesTheBoundCustomerFilter(): void
    {
        // The exclusion must never come at the cost of the customer scope.
        $equals = array_values(array_filter(
            self::scope()->quoteCriteria()->getFilters(),
            static fn(object $f): bool => $f instanceof EqualsFilter,
        ));

        self::assertCount(1, $equals);
        self::assertSame('customerId', $equals[0]->getField());
        self::assertSame(self::COMPANY, $equals[0]->getValue());
    }

    public function testAnEmptyServicedQuoteIdAddsNoExclusionRatherThanAnInvalidOne(): void
    {
        // The safe direction, and it is not the same call as the customer filter.
        // The customer filter IS the boundary, so it is applied even for an empty
        // id. This exclusion only refines an already-bounded set, so dropping it
        // can at most leave one extra quote of the SAME customer in their history.
        // It also cannot be applied blindly: the DAL parses an `id` filter as a
        // UUID and throws InvalidUuidException on anything else, so filtering on
        // '' would abort the whole read. Measured against the real shop.
        $filters = (new CustomerScope(self::COMPANY, '', new QuoteVersionResolver()))->quoteCriteria()->getFilters();

        self::assertCount(1, $filters, 'Only the customer filter, and no invalid id filter.');
        self::assertInstanceOf(EqualsFilter::class, $filters[0]);
        self::assertSame('customerId', $filters[0]->getField());
    }
}
